<?php

namespace App\Webhooks\Verification;

/**
 * Verifies the X-Shopify-Hmac-Sha256 header: base64(HMAC-SHA256(raw body, app client secret)).
 *
 * Shopify signs only the body. Its timestamp and id headers are not signed, so replay
 * protection comes from inbox dedup and idempotent processing rather than a time window.
 */
class ShopifySignatureVerifier
{
    public function __construct(private readonly ?string $secret) {}

    public function verify(string $rawBody, ?string $signatureHeader): void
    {
        if ($this->secret === null || $this->secret === '') {
            throw new InvalidWebhookSignature('Shopify webhook secret is not configured.');
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            throw new InvalidWebhookSignature('Missing X-Shopify-Hmac-Sha256 header.');
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $this->secret, true));

        if (! hash_equals($expected, $signatureHeader)) {
            throw new InvalidWebhookSignature('Shopify signature mismatch.');
        }
    }

    public static function sign(string $rawBody, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    }
}
