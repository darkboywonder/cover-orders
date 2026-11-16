<?php

namespace App\Webhooks\Verification;

use Carbon\CarbonImmutable;

/**
 * Verifies Recharge webhooks against the store's API client secret.
 *
 * Preferred scheme: X-Recharge-Webhook-Signature "t=<epoch>,v1=<hex>" where
 * v1 = hex(HMAC-SHA256("<t>.<raw body>", secret)). The signed timestamp must be
 * within the tolerance window. Recharge retries for up to two days, so the default
 * window matches that; a shorter window would reject legitimate retries.
 *
 * Legacy scheme (optional): X-Recharge-Hmac-Sha256 = hex(sha256(secret . raw body)).
 * It carries no timestamp, so it is accepted only when explicitly allowed.
 */
class RechargeSignatureVerifier
{
    public function __construct(
        private readonly int $toleranceSeconds,
        private readonly bool $allowLegacySignature,
    ) {}

    public function verify(string $rawBody, ?string $secret, ?string $signatureHeader, ?string $legacyHeader): void
    {
        if ($secret === null || $secret === '') {
            throw new InvalidWebhookSignature('Recharge webhook secret is not configured for this shop.');
        }

        if ($signatureHeader !== null && $signatureHeader !== '') {
            $this->verifyTimestampedSignature($rawBody, $secret, $signatureHeader);

            return;
        }

        if ($this->allowLegacySignature && $legacyHeader !== null && $legacyHeader !== '') {
            if (! hash_equals(hash('sha256', $secret.$rawBody), strtolower($legacyHeader))) {
                throw new InvalidWebhookSignature('Recharge legacy signature mismatch.');
            }

            return;
        }

        throw new InvalidWebhookSignature('Missing Recharge signature header.');
    }

    private function verifyTimestampedSignature(string $rawBody, string $secret, string $signatureHeader): void
    {
        $parts = $this->parseSignatureHeader($signatureHeader);

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            throw new InvalidWebhookSignature('Malformed X-Recharge-Webhook-Signature header.');
        }

        $age = abs(CarbonImmutable::now()->getTimestamp() - (int) $parts['t']);

        if ($age > $this->toleranceSeconds) {
            throw new InvalidWebhookSignature('Recharge signature timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $parts['t'].'.'.$rawBody, $secret);

        if (! hash_equals($expected, strtolower($parts['v1']))) {
            throw new InvalidWebhookSignature('Recharge signature mismatch.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, '');

            if ($key !== '' && $value !== '') {
                $parts[$key] = $value;
            }
        }

        return $parts;
    }

    /**
     * Build a header value the way Recharge does. Used by the simulator and tests.
     */
    public static function sign(string $rawBody, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
