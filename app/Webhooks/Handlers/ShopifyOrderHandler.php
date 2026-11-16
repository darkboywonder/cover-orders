<?php

namespace App\Webhooks\Handlers;

use App\Coverage\CoverageService;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Webhooks\HandlerResult;
use App\Webhooks\Payloads\ShopifyLineItem;
use App\Webhooks\Payloads\ShopifyOrderPayload;

/**
 * Handles orders/create, orders/updated and orders/cancelled. All three carry the
 * full Order, so one handler upserts the order and re-syncs its coverage.
 */
class ShopifyOrderHandler implements WebhookHandler
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function handle(WebhookEvent $event): HandlerResult
    {
        $payload = ShopifyOrderPayload::fromArray($event->decodedPayload());

        $order = Order::query()
            ->where('shop_id', $event->shop_id)
            ->where('shopify_order_id', $payload->id)
            ->lockForUpdate()
            ->first() ?? new Order(['shop_id' => $event->shop_id, 'shopify_order_id' => $payload->id]);

        if ($order->exists && $order->source_updated_at && $payload->updatedAt?->lessThan($order->source_updated_at)) {
            return HandlerResult::ignored('Stale: a newer version of this order was already applied.');
        }

        $protectionLines = $payload->protectionLineItems(
            config('coverage.protection_skus'),
            config('coverage.protection_vendors'),
        );

        $order->fill([
            'admin_graphql_api_id' => $payload->adminGraphqlApiId,
            'name' => $payload->name,
            'currency' => $payload->currency,
            'total_price_cents' => $payload->totalPriceCents,
            'has_protection_line' => $protectionLines !== [],
            'protection_price_cents' => array_sum(array_map(fn (ShopifyLineItem $line): int => $line->netCents(), $protectionLines)),
            'placed_at' => $order->placed_at ?? $payload->createdAt,
            // Shopify orders cannot be un-cancelled, so a cancellation is never cleared.
            'cancelled_at' => $payload->cancelledAt ?? $order->cancelled_at,
            'source_updated_at' => $payload->updatedAt,
        ])->save();

        $this->coverage->syncOrder($order);
        $this->coverage->syncSubscriptionsOriginatingFrom($order);

        return HandlerResult::processed();
    }
}
