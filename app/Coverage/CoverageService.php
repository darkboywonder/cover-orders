<?php

namespace App\Coverage;

use App\Enums\CoverageReason;
use App\Enums\CoverageStatus;
use App\Enums\OrderOrigin;
use App\Models\Coverage;
use App\Models\Order;
use App\Models\Subscription;

/**
 * The single place that decides what is covered and therefore billable.
 *
 * Every method recomputes the desired state from what is stored and then makes the
 * coverage record match it. Because nothing depends on the order events arrive in,
 * duplicate, late or replayed events always converge on the same result.
 */
class CoverageService
{
    /**
     * @param  array<int, string>  $billableRechargeStatuses
     */
    public function __construct(private readonly array $billableRechargeStatuses = ['success']) {}

    /**
     * Bring the order's coverage in line with its current state.
     */
    public function syncOrder(Order $order): ?Coverage
    {
        $order->loadMissing('subscription', 'coverage');
        $coverage = $order->coverage;

        if ($order->isCancelled()) {
            return $this->void($coverage, 'order_cancelled');
        }

        $eligibility = $this->eligibility($order);

        if ($eligibility === null) {
            // Coverage inherited from a subscription is never taken back by later
            // subscription changes; only a cancelled order or a removed protection line voids coverage.
            if ($coverage?->reason === CoverageReason::ProtectionLine) {
                return $this->void($coverage, 'protection_removed');
            }

            return $coverage;
        }

        $attributes = [
            'shop_id' => $order->shop_id,
            'subscription_id' => $eligibility['reason'] === CoverageReason::SubscriptionInherited ? $order->subscription_id : null,
            'reason' => $eligibility['reason'],
            'premium_cents' => $eligibility['premium_cents'],
            'currency' => $eligibility['currency'],
            'status' => CoverageStatus::Active,
            'voided_at' => null,
            'void_reason' => null,
        ];

        if ($coverage === null) {
            return $order->coverage()->create([
                ...$attributes,
                'covered_at' => $order->placed_at ?? now(),
            ]);
        }

        $coverage->fill($attributes)->save();

        return $coverage;
    }

    /**
     * Recompute whether a subscription is covered from its origin order, then
     * re-sync the recurring orders that depend on it.
     */
    public function syncSubscription(Subscription $subscription): void
    {
        $origin = $this->resolveOriginOrder($subscription);
        $originCoverage = $origin?->coverage()->first();
        $isCovered = $originCoverage !== null && $originCoverage->isActive();

        $subscription->fill([
            'origin_order_id' => $origin?->id,
            'is_covered' => $isCovered,
            'covered_premium_cents' => $isCovered ? $originCoverage->premium_cents : $subscription->covered_premium_cents,
            'currency' => $isCovered ? $originCoverage->currency : $subscription->currency,
        ])->save();

        $subscription->orders()
            ->where('origin', OrderOrigin::RechargeRecurring)
            ->with('coverage')
            ->get()
            ->each(function (Order $order) use ($subscription): void {
                $order->setRelation('subscription', $subscription);
                $this->syncOrder($order);
            });
    }

    /**
     * Re-sync every subscription whose original order is this order. Called after a
     * Shopify order is stored, which may be after Recharge already told us about the link.
     */
    public function syncSubscriptionsOriginatingFrom(Order $order): void
    {
        Subscription::query()
            ->where('shop_id', $order->shop_id)
            ->where('origin_shopify_order_id', $order->shopify_order_id)
            ->get()
            ->each(fn (Subscription $subscription) => $this->syncSubscription($subscription));
    }

    /**
     * @return array{reason: CoverageReason, premium_cents: int, currency: string}|null
     */
    private function eligibility(Order $order): ?array
    {
        if ($order->has_protection_line && $order->protection_price_cents > 0 && $order->currency) {
            return [
                'reason' => CoverageReason::ProtectionLine,
                'premium_cents' => $order->protection_price_cents,
                'currency' => $order->currency,
            ];
        }

        $subscription = $order->subscription;

        if (
            $order->origin === OrderOrigin::RechargeRecurring
            && in_array($order->recharge_status, $this->billableRechargeStatuses, true)
            && $subscription !== null
            && $subscription->coversOrderPlacedAt($order->placed_at)
        ) {
            return [
                'reason' => CoverageReason::SubscriptionInherited,
                'premium_cents' => $subscription->covered_premium_cents,
                'currency' => $subscription->currency ?? $order->currency,
            ];
        }

        return null;
    }

    private function resolveOriginOrder(Subscription $subscription): ?Order
    {
        if ($subscription->origin_shopify_order_id === null) {
            return null;
        }

        return Order::query()
            ->where('shop_id', $subscription->shop_id)
            ->where('shopify_order_id', $subscription->origin_shopify_order_id)
            ->first();
    }

    private function void(?Coverage $coverage, string $reason): ?Coverage
    {
        if ($coverage === null || ! $coverage->isActive()) {
            return $coverage;
        }

        $coverage->fill([
            'status' => CoverageStatus::Voided,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        return $coverage;
    }
}
