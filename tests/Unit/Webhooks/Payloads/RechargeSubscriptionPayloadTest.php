<?php

namespace Tests\Unit\Webhooks\Payloads;

use App\Enums\SubscriptionStatus;
use App\Webhooks\Payloads\RechargeSubscriptionPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RechargeSubscriptionPayloadTest extends TestCase
{
    public function test_parses_the_documented_subscription(): void
    {
        $payload = RechargeSubscriptionPayload::fromWebhook($this->fixture('subscription-created'));

        $this->assertSame('398712345', $payload->id);
        $this->assertSame('154329876', $payload->customerId);
        $this->assertSame('187654321', $payload->addressId);
        $this->assertSame(SubscriptionStatus::Active, $payload->status);
        $this->assertSame('8123450001', $payload->externalProductId);
        $this->assertSame('month', $payload->orderIntervalUnit);
        $this->assertSame(1, $payload->orderIntervalFrequency);
        $this->assertSame('2026-12-01', $payload->nextChargeScheduledAt->toDateString());
        $this->assertNull($payload->cancelledAt);
    }

    public function test_parses_cancellation(): void
    {
        $payload = RechargeSubscriptionPayload::fromWebhook($this->fixture('subscription-cancelled'));

        $this->assertSame(SubscriptionStatus::Cancelled, $payload->status);
        $this->assertSame('2026-11-10 14:30:00', $payload->cancelledAt->toDateTimeString());
    }

    public function test_rejects_a_body_without_the_subscription_wrapper(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RechargeSubscriptionPayload::fromWebhook(['id' => 398712345]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/../../../Fixtures/recharge/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    }
}
