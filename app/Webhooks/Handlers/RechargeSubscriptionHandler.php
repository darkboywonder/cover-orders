<?php

namespace App\Webhooks\Handlers;

use App\Coverage\CoverageService;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Webhooks\HandlerResult;
use App\Webhooks\Payloads\RechargeSubscriptionPayload;

/**
 * Handles subscription/created, updated, activated, cancelled and expired. All carry
 * the full Subscription, so the stored row is simply brought up to date.
 */
class RechargeSubscriptionHandler implements WebhookHandler
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function handle(WebhookEvent $event): HandlerResult
    {
        $payload = RechargeSubscriptionPayload::fromWebhook($event->decodedPayload());

        $subscription = Subscription::query()
            ->where('shop_id', $event->shop_id)
            ->where('recharge_subscription_id', $payload->id)
            ->lockForUpdate()
            ->first() ?? new Subscription(['shop_id' => $event->shop_id, 'recharge_subscription_id' => $payload->id]);

        if ($subscription->exists && $subscription->source_updated_at && $payload->updatedAt?->lessThan($subscription->source_updated_at)) {
            return HandlerResult::ignored('Stale: a newer version of this subscription was already applied.');
        }

        $subscription->fill([
            'recharge_customer_id' => $payload->customerId,
            'recharge_address_id' => $payload->addressId,
            'status' => $payload->status,
            'external_product_id' => $payload->externalProductId,
            'sku' => $payload->sku,
            'order_interval_unit' => $payload->orderIntervalUnit,
            'order_interval_frequency' => $payload->orderIntervalFrequency,
            'next_charge_scheduled_at' => $payload->nextChargeScheduledAt,
            'cancelled_at' => $payload->cancelledAt,
            'source_updated_at' => $payload->updatedAt,
        ])->save();

        $this->coverage->syncSubscription($subscription);

        return HandlerResult::processed();
    }
}
