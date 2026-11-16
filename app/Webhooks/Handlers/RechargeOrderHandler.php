<?php

namespace App\Webhooks\Handlers;

use App\Coverage\CoverageService;
use App\Enums\OrderOrigin;
use App\Enums\SubscriptionStatus;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Webhooks\HandlerResult;
use App\Webhooks\Payloads\RechargeOrderPayload;

/**
 * Handles Recharge order/* topics.
 *
 * - checkout orders link each subscription to the Shopify order the customer placed,
 *   which is how a subscription learns whether it is covered;
 * - recurring orders are the orders Recharge places later. The same order also reaches
 *   us from Shopify, so both converge on one Order row keyed by the Shopify order id.
 */
class RechargeOrderHandler implements WebhookHandler
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function handle(WebhookEvent $event): HandlerResult
    {
        $payload = RechargeOrderPayload::fromWebhook($event->decodedPayload());

        if ($payload->shopifyOrderId === null) {
            return HandlerResult::ignored('Recharge order has no Shopify order yet (external_order_id is empty).');
        }

        if ($payload->subscriptionIds === []) {
            return HandlerResult::ignored('Recharge order has no subscription line items.');
        }

        return match (true) {
            $payload->isCheckout() => $this->linkSubscriptionsToOrigin($event, $payload),
            $payload->isRecurring() => $this->recordRecurringOrder($event, $payload),
            default => HandlerResult::ignored("Unknown Recharge order type [{$payload->type}]."),
        };
    }

    private function linkSubscriptionsToOrigin(WebhookEvent $event, RechargeOrderPayload $payload): HandlerResult
    {
        foreach ($payload->subscriptionIds as $subscriptionId) {
            $subscription = $this->findOrCreateSubscription($event->shop_id, $subscriptionId, $payload);

            $subscription->origin_shopify_order_id ??= $payload->shopifyOrderId;
            $subscription->save();

            $this->coverage->syncSubscription($subscription);
        }

        return HandlerResult::processed();
    }

    private function recordRecurringOrder(WebhookEvent $event, RechargeOrderPayload $payload): HandlerResult
    {
        $order = Order::query()
            ->where('shop_id', $event->shop_id)
            ->where('shopify_order_id', $payload->shopifyOrderId)
            ->lockForUpdate()
            ->first() ?? new Order([
                'shop_id' => $event->shop_id,
                'shopify_order_id' => $payload->shopifyOrderId,
                // Shopify is the source of truth for these; they are only defaults until its webhook arrives.
                'name' => $payload->shopifyOrderName,
                'currency' => $payload->currency,
                'total_price_cents' => $payload->totalPriceCents,
            ]);

        if ($order->recharge_updated_at && $payload->updatedAt?->lessThan($order->recharge_updated_at)) {
            return HandlerResult::ignored('Stale: a newer version of this Recharge order was already applied.');
        }

        $subscription = $this->findOrCreateSubscription($event->shop_id, $payload->subscriptionIds[0], $payload);

        $order->fill([
            'origin' => OrderOrigin::RechargeRecurring,
            'recharge_order_id' => $payload->id,
            'recharge_status' => $payload->status,
            'subscription_id' => $subscription->id,
            'placed_at' => $order->placed_at ?? $payload->processedAt ?? $payload->createdAt,
            'recharge_updated_at' => $payload->updatedAt,
        ]);

        if ($payload->status === RechargeOrderPayload::STATUS_CANCELLED) {
            $order->cancelled_at ??= $payload->updatedAt ?? now();
        }

        $order->save();
        $order->setRelation('subscription', $subscription);

        $this->coverage->syncOrder($order);

        return HandlerResult::processed();
    }

    /**
     * A Recharge order can arrive before the subscription/created webhook. Create a
     * placeholder that the subscription webhook fills in when it lands.
     */
    private function findOrCreateSubscription(int $shopId, string $subscriptionId, RechargeOrderPayload $payload): Subscription
    {
        return Subscription::query()
            ->where('shop_id', $shopId)
            ->where('recharge_subscription_id', $subscriptionId)
            ->lockForUpdate()
            ->first() ?? Subscription::create([
                'shop_id' => $shopId,
                'recharge_subscription_id' => $subscriptionId,
                'recharge_customer_id' => $payload->customerId,
                'recharge_address_id' => $payload->addressId,
                'status' => SubscriptionStatus::Active,
            ]);
    }
}
