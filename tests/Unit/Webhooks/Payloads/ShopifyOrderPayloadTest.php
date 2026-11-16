<?php

namespace Tests\Unit\Webhooks\Payloads;

use App\Webhooks\Payloads\ShopifyOrderPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ShopifyOrderPayloadTest extends TestCase
{
    public function test_parses_the_documented_orders_create_payload(): void
    {
        $payload = ShopifyOrderPayload::fromArray($this->fixture('orders-create-covered'));

        $this->assertSame('5810001001001', $payload->id);
        $this->assertSame('gid://shopify/Order/5810001001001', $payload->adminGraphqlApiId);
        $this->assertSame('#1001', $payload->name);
        $this->assertSame('USD', $payload->currency);
        $this->assertSame(3495, $payload->totalPriceCents);
        $this->assertCount(2, $payload->lineItems);
        $this->assertSame('2026-10-01 18:05:11', $payload->createdAt->toDateTimeString());
        $this->assertNull($payload->cancelledAt);
    }

    public function test_finds_the_protection_line_by_sku(): void
    {
        $payload = ShopifyOrderPayload::fromArray($this->fixture('orders-create-covered'));

        $lines = $payload->protectionLineItems(['shipinsure-protection'], []);

        $this->assertCount(1, $lines);
        $this->assertSame(295, $lines[0]->netCents());
    }

    public function test_falls_back_to_vendor_when_the_protection_line_has_no_sku(): void
    {
        $order = $this->fixture('orders-create-covered');
        $order['line_items'][1]['sku'] = null;

        $lines = ShopifyOrderPayload::fromArray($order)->protectionLineItems(['SHIPINSURE-PROTECTION'], ['ShipInsure']);

        $this->assertCount(1, $lines);
    }

    public function test_order_without_the_protection_product_has_no_protection_lines(): void
    {
        $payload = ShopifyOrderPayload::fromArray($this->fixture('orders-create-uncovered'));

        $this->assertSame([], $payload->protectionLineItems(['SHIPINSURE-PROTECTION'], ['ShipInsure']));
    }

    public function test_parses_cancellation_time(): void
    {
        $payload = ShopifyOrderPayload::fromArray($this->fixture('orders-cancelled-covered'));

        $this->assertSame('2026-10-02 13:00:00', $payload->cancelledAt->toDateTimeString());
    }

    public function test_rejects_a_payload_without_an_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ShopifyOrderPayload::fromArray(['name' => '#1001']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/../../../Fixtures/shopify/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    }
}
