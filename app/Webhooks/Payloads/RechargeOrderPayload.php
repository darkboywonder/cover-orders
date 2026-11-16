<?php

namespace App\Webhooks\Payloads;

use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The Recharge (2021-11) Order object, delivered as {"order": {...}}.
 * external_order_id.ecommerce is the Shopify order id; subscription line items
 * point at their subscription through purchase_item_id.
 */
final readonly class RechargeOrderPayload
{
    public const TYPE_CHECKOUT = 'checkout';

    public const TYPE_RECURRING = 'recurring';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param  array<int, string>  $subscriptionIds
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public ?string $shopifyOrderId,
        public ?string $shopifyOrderName,
        public ?string $currency,
        public int $totalPriceCents,
        public ?string $customerId,
        public ?string $addressId,
        public array $subscriptionIds,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $processedAt,
        public ?CarbonImmutable $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromWebhook(array $payload): self
    {
        $order = $payload['order'] ?? null;

        if (! is_array($order) || ! isset($order['id'])) {
            throw new InvalidArgumentException('Recharge order payload has no "order" object.');
        }

        $subscriptionIds = collect($order['line_items'] ?? [])
            ->filter(fn (array $item): bool => ($item['purchase_item_type'] ?? null) === 'subscription' && isset($item['purchase_item_id']))
            ->map(fn (array $item): string => (string) $item['purchase_item_id'])
            ->unique()
            ->values()
            ->all();

        $externalOrderId = $order['external_order_id']['ecommerce'] ?? null;

        return new self(
            id: (string) $order['id'],
            type: (string) ($order['type'] ?? ''),
            status: (string) ($order['status'] ?? ''),
            shopifyOrderId: $externalOrderId !== null && $externalOrderId !== '' ? (string) $externalOrderId : null,
            shopifyOrderName: $order['external_order_name']['ecommerce'] ?? null,
            currency: isset($order['currency']) ? strtoupper((string) $order['currency']) : null,
            totalPriceCents: Money::toCents($order['total_price'] ?? null),
            customerId: isset($order['customer']['id']) ? (string) $order['customer']['id'] : null,
            addressId: isset($order['address_id']) ? (string) $order['address_id'] : null,
            subscriptionIds: $subscriptionIds,
            createdAt: Timestamp::parse($order['created_at'] ?? null),
            processedAt: Timestamp::parse($order['processed_at'] ?? null),
            updatedAt: Timestamp::parse($order['updated_at'] ?? null),
        );
    }

    public function isCheckout(): bool
    {
        return $this->type === self::TYPE_CHECKOUT;
    }

    public function isRecurring(): bool
    {
        return $this->type === self::TYPE_RECURRING;
    }
}
