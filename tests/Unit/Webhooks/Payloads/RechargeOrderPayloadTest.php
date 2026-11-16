<?php

namespace Tests\Unit\Webhooks\Payloads;

use App\Webhooks\Payloads\RechargeOrderPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RechargeOrderPayloadTest extends TestCase
{
    public function test_parses_the_documented_checkout_order(): void
    {
        $payload = RechargeOrderPayload::fromWebhook($this->fixture('order-checkout'));

        $this->assertSame('612340001', $payload->id);
        $this->assertTrue($payload->isCheckout());
        $this->assertSame('success', $payload->status);
        $this->assertSame('5810001001001', $payload->shopifyOrderId);
        $this->assertSame('#1001', $payload->shopifyOrderName);
        $this->assertSame('USD', $payload->currency);
        $this->assertSame('154329876', $payload->customerId);
    }

    public function test_only_subscription_line_items_link_to_subscriptions(): void
    {
        $payload = RechargeOrderPayload::fromWebhook($this->fixture('order-checkout'));

        $this->assertSame(['398712345'], $payload->subscriptionIds);
    }

    public function test_parses_the_recurring_order(): void
    {
        $payload = RechargeOrderPayload::fromWebhook($this->fixture('order-recurring-success'));

        $this->assertTrue($payload->isRecurring());
        $this->assertSame('5810001002002', $payload->shopifyOrderId);
        $this->assertSame('2026-11-01 14:00:03', $payload->processedAt->toDateTimeString());
    }

    public function test_queued_order_has_no_shopify_order_yet(): void
    {
        $payload = RechargeOrderPayload::fromWebhook($this->fixture('order-recurring-queued'));

        $this->assertNull($payload->shopifyOrderId);
        $this->assertNull($payload->processedAt);
    }

    public function test_rejects_a_body_without_the_order_wrapper(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RechargeOrderPayload::fromWebhook(['id' => 612340001]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/../../../Fixtures/recharge/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    }
}
