<?php

namespace Tests\Feature\Coverage;

use App\Enums\CoverageReason;
use App\Enums\CoverageStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Coverage;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/**
 * End-to-end: signed webhooks in, billable coverage records out. The test queue runs
 * synchronously, so each request is fully processed before the next one is sent.
 */
class CoverageLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase, SendsWebhooks;

    private const ORIGIN_ORDER_ID = '5810001001001';

    private const RECURRING_ORDER_ID = '5810001002002';

    private const SUBSCRIPTION_ID = '398712345';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
    }

    public function test_checkout_order_with_protection_line_creates_billable_coverage(): void
    {
        $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-covered'))->assertOk();

        $coverage = Coverage::sole();
        $this->assertSame(CoverageReason::ProtectionLine, $coverage->reason);
        $this->assertSame(CoverageStatus::Active, $coverage->status);
        $this->assertSame(295, $coverage->premium_cents);
        $this->assertSame('USD', $coverage->currency);
        $this->assertSame(self::ORIGIN_ORDER_ID, $coverage->order->shopify_order_id);
        $this->assertSame('2026-10-01 18:05:11', $coverage->covered_at->toDateTimeString());
        $this->assertSame(WebhookEventStatus::Processed, WebhookEvent::sole()->status);
    }

    public function test_order_without_protection_line_creates_no_coverage(): void
    {
        $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-uncovered'))->assertOk();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseEmpty('coverages');
    }

    public function test_protection_line_discount_reduces_the_premium(): void
    {
        $payload = $this->fixture('shopify/orders-create-covered');
        $payload['line_items'][1]['discount_allocations'] = [['amount' => '1.00', 'discount_application_index' => 0]];

        $this->postShopifyWebhook($this->shop, 'orders/create', $payload)->assertOk();

        $this->assertSame(195, Coverage::sole()->premium_cents);
    }

    public function test_recharge_checkout_order_links_subscription_to_covered_origin(): void
    {
        $this->sendCoveredCheckout();

        $subscription = Subscription::sole();
        $this->assertTrue($subscription->is_covered);
        $this->assertSame(self::ORIGIN_ORDER_ID, $subscription->origin_shopify_order_id);
        $this->assertSame(295, $subscription->covered_premium_cents);
        $this->assertSame('USD', $subscription->currency);
        $this->assertTrue($subscription->originOrder->is(Order::where('shopify_order_id', self::ORIGIN_ORDER_ID)->sole()));
    }

    public function test_recurring_order_of_covered_subscription_is_covered_at_the_origin_premium(): void
    {
        $this->sendCoveredCheckout();

        $this->postRechargeWebhook($this->shop, 'order/processed', $this->fixture('recharge/order-recurring-success'))->assertOk();

        $coverage = $this->coverageFor(self::RECURRING_ORDER_ID);
        $this->assertSame(CoverageReason::SubscriptionInherited, $coverage->reason);
        $this->assertSame(295, $coverage->premium_cents);
        $this->assertSame(self::SUBSCRIPTION_ID, $coverage->subscription->recharge_subscription_id);
        $this->assertSame('2026-11-01 14:00:03', $coverage->covered_at->toDateTimeString());
    }

    /**
     * @return array<string, array{0: array<int, string>}>
     */
    public static function recurringDeliveryOrders(): array
    {
        return [
            'recharge first' => [['recharge', 'shopify']],
            'shopify first' => [['shopify', 'recharge']],
        ];
    }

    #[DataProvider('recurringDeliveryOrders')]
    public function test_recurring_order_reported_by_both_platforms_is_billed_once(array $deliveryOrder): void
    {
        $this->sendCoveredCheckout();

        foreach ($deliveryOrder as $source) {
            $source === 'recharge'
                ? $this->postRechargeWebhook($this->shop, 'order/processed', $this->fixture('recharge/order-recurring-success'))->assertOk()
                : $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-recurring'))->assertOk();
        }

        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('coverages', 2);
        $coverage = $this->coverageFor(self::RECURRING_ORDER_ID);
        $this->assertSame(CoverageReason::SubscriptionInherited, $coverage->reason);
        $this->assertSame('#1002', $coverage->order->name);
        $this->assertSame(3200, $coverage->order->total_price_cents);
    }

    public function test_events_delivered_in_reverse_order_converge_on_the_same_coverage(): void
    {
        $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-recurring'))->assertOk();
        $this->postRechargeWebhook($this->shop, 'order/processed', $this->fixture('recharge/order-recurring-success'))->assertOk();
        $this->postRechargeWebhook($this->shop, 'order/created', $this->fixture('recharge/order-checkout'))->assertOk();
        $this->postRechargeWebhook($this->shop, 'subscription/created', $this->fixture('recharge/subscription-created'))->assertOk();
        $this->assertDatabaseEmpty('coverages');

        $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-covered'))->assertOk();

        $this->assertSame(CoverageReason::ProtectionLine, $this->coverageFor(self::ORIGIN_ORDER_ID)->reason);
        $this->assertSame(CoverageReason::SubscriptionInherited, $this->coverageFor(self::RECURRING_ORDER_ID)->reason);
        $this->assertTrue(Subscription::sole()->is_covered);
    }

    public function test_subscription_from_uncovered_order_does_not_cover_its_recurring_orders(): void
    {
        $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-uncovered'))->assertOk();
        $this->postRechargeWebhook($this->shop, 'order/created', $this->fixture('recharge/order-checkout-uncovered'))->assertOk();
        $recurring = $this->fixture('recharge/order-recurring-success');
        $recurring['order']['line_items'][0]['purchase_item_id'] = 398712399;

        $this->postRechargeWebhook($this->shop, 'order/processed', $recurring)->assertOk();

        $this->assertFalse(Subscription::sole()->is_covered);
        $this->assertDatabaseEmpty('coverages');
    }

    public function test_replayed_body_under_a_new_webhook_id_does_not_create_a_second_coverage(): void
    {
        $payload = $this->fixture('shopify/orders-create-covered');

        $this->postShopifyWebhook($this->shop, 'orders/create', $payload, ['X-Shopify-Webhook-Id' => 'original'])->assertOk();
        $this->postShopifyWebhook($this->shop, 'orders/create', $payload, ['X-Shopify-Webhook-Id' => 'forged-replay'])->assertOk();

        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('coverages', 1);
    }

    public function test_cancelled_order_voids_its_coverage(): void
    {
        $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-covered'))->assertOk();

        $this->postShopifyWebhook($this->shop, 'orders/cancelled', $this->fixture('shopify/orders-cancelled-covered'))->assertOk();

        $coverage = Coverage::sole();
        $this->assertSame(CoverageStatus::Voided, $coverage->status);
        $this->assertSame('order_cancelled', $coverage->void_reason);
    }

    public function test_stale_order_update_arriving_late_is_ignored(): void
    {
        $this->postShopifyWebhook($this->shop, 'orders/cancelled', $this->fixture('shopify/orders-cancelled-covered'))->assertOk();

        $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-covered'))->assertOk();

        $this->assertNotNull(Order::sole()->cancelled_at);
        $this->assertSame(WebhookEventStatus::Ignored, WebhookEvent::latest('id')->first()->status);
        $this->assertDatabaseMissing('coverages', ['status' => CoverageStatus::Active]);
    }

    public function test_cancelling_the_origin_order_stops_covering_future_recurring_orders(): void
    {
        $this->sendCoveredCheckout();

        $this->postShopifyWebhook($this->shop, 'orders/cancelled', $this->fixture('shopify/orders-cancelled-covered'))->assertOk();
        $this->postRechargeWebhook($this->shop, 'order/processed', $this->fixture('recharge/order-recurring-success'))->assertOk();

        $this->assertFalse(Subscription::sole()->is_covered);
        $this->assertNull(Order::where('shopify_order_id', self::RECURRING_ORDER_ID)->sole()->coverage);
    }

    public function test_cancelling_the_subscription_keeps_coverage_already_granted(): void
    {
        $this->sendCoveredCheckout();
        $this->postRechargeWebhook($this->shop, 'order/processed', $this->fixture('recharge/order-recurring-success'))->assertOk();

        $this->postRechargeWebhook($this->shop, 'subscription/cancelled', $this->fixture('recharge/subscription-cancelled'))->assertOk();

        $this->assertTrue($this->coverageFor(self::RECURRING_ORDER_ID)->isActive());
    }

    public function test_recurring_order_placed_after_subscription_cancellation_is_not_covered(): void
    {
        $this->sendCoveredCheckout();
        $this->postRechargeWebhook($this->shop, 'subscription/cancelled', $this->fixture('recharge/subscription-cancelled'))->assertOk();
        $lateOrder = $this->fixture('recharge/order-recurring-success');
        $lateOrder['order']['id'] = 612340005;
        $lateOrder['order']['external_order_id']['ecommerce'] = '5810001005005';
        $lateOrder['order']['processed_at'] = '2026-11-12T14:00:00+00:00';
        $lateOrder['order']['updated_at'] = '2026-11-12T14:00:05+00:00';

        $this->postRechargeWebhook($this->shop, 'order/processed', $lateOrder)->assertOk();

        $this->assertNull(Order::where('shopify_order_id', '5810001005005')->sole()->coverage);
    }

    public function test_queued_recurring_order_without_a_shopify_order_is_ignored(): void
    {
        $this->sendCoveredCheckout();

        $this->postRechargeWebhook($this->shop, 'order/created', $this->fixture('recharge/order-recurring-queued'))->assertOk();

        $event = WebhookEvent::latest('id')->first();
        $this->assertSame(WebhookEventStatus::Ignored, $event->status);
        $this->assertStringContainsString('no Shopify order yet', $event->last_error);
        $this->assertDatabaseCount('coverages', 1);
    }

    public function test_unhandled_topic_is_acknowledged_and_ignored(): void
    {
        $this->postShopifyWebhook($this->shop, 'products/update', '{"id":1}')->assertOk();

        $this->assertSame(WebhookEventStatus::Ignored, WebhookEvent::sole()->status);
    }

    private function sendCoveredCheckout(): void
    {
        $this->postShopifyWebhook($this->shop, 'orders/create', $this->fixture('shopify/orders-create-covered'))->assertOk();
        $this->postRechargeWebhook($this->shop, 'subscription/created', $this->fixture('recharge/subscription-created'))->assertOk();
        $this->postRechargeWebhook($this->shop, 'order/created', $this->fixture('recharge/order-checkout'))->assertOk();
    }

    private function coverageFor(string $shopifyOrderId): Coverage
    {
        return Order::where('shopify_order_id', $shopifyOrderId)->sole()->coverage;
    }
}
