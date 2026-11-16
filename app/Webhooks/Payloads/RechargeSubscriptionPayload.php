<?php

namespace App\Webhooks\Payloads;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The Recharge (2021-11) Subscription object, delivered as {"subscription": {...}}.
 * It does not reference the Shopify order that created it; that link comes from
 * the Recharge checkout Order.
 */
final readonly class RechargeSubscriptionPayload
{
    public function __construct(
        public string $id,
        public ?string $customerId,
        public ?string $addressId,
        public SubscriptionStatus $status,
        public ?string $externalProductId,
        public ?string $sku,
        public ?string $orderIntervalUnit,
        public ?int $orderIntervalFrequency,
        public ?CarbonImmutable $nextChargeScheduledAt,
        public ?CarbonImmutable $cancelledAt,
        public ?CarbonImmutable $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromWebhook(array $payload): self
    {
        $subscription = $payload['subscription'] ?? null;

        if (! is_array($subscription) || ! isset($subscription['id'])) {
            throw new InvalidArgumentException('Recharge subscription payload has no "subscription" object.');
        }

        return new self(
            id: (string) $subscription['id'],
            customerId: isset($subscription['customer_id']) ? (string) $subscription['customer_id'] : null,
            addressId: isset($subscription['address_id']) ? (string) $subscription['address_id'] : null,
            status: SubscriptionStatus::tryFrom((string) ($subscription['status'] ?? '')) ?? SubscriptionStatus::Active,
            externalProductId: isset($subscription['external_product_id']['ecommerce'])
                ? (string) $subscription['external_product_id']['ecommerce']
                : null,
            sku: $subscription['sku'] ?? null,
            orderIntervalUnit: $subscription['order_interval_unit'] ?? null,
            orderIntervalFrequency: isset($subscription['order_interval_frequency'])
                ? (int) $subscription['order_interval_frequency']
                : null,
            nextChargeScheduledAt: Timestamp::parse($subscription['next_charge_scheduled_at'] ?? null),
            cancelledAt: Timestamp::parse($subscription['cancelled_at'] ?? null),
            updatedAt: Timestamp::parse($subscription['updated_at'] ?? null),
        );
    }
}
