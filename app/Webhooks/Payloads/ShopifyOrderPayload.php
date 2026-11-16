<?php

namespace App\Webhooks\Payloads;

use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The Shopify Order as delivered by orders/create, orders/updated and orders/cancelled.
 * Webhooks use the REST serialization of the Order object (snake_case, numeric id),
 * with admin_graphql_api_id linking it to the GraphQL Order.
 */
final readonly class ShopifyOrderPayload
{
    /**
     * @param  array<int, ShopifyLineItem>  $lineItems
     */
    public function __construct(
        public string $id,
        public ?string $adminGraphqlApiId,
        public ?string $name,
        public ?string $currency,
        public int $totalPriceCents,
        public array $lineItems,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $updatedAt,
        public ?CarbonImmutable $cancelledAt,
    ) {}

    /**
     * @param  array<string, mixed>  $order
     */
    public static function fromArray(array $order): self
    {
        if (! isset($order['id'])) {
            throw new InvalidArgumentException('Shopify order payload has no id.');
        }

        return new self(
            id: (string) $order['id'],
            adminGraphqlApiId: $order['admin_graphql_api_id'] ?? null,
            name: $order['name'] ?? null,
            currency: isset($order['currency']) ? strtoupper((string) $order['currency']) : null,
            totalPriceCents: Money::toCents($order['total_price'] ?? null),
            lineItems: array_map(ShopifyLineItem::fromArray(...), $order['line_items'] ?? []),
            createdAt: Timestamp::parse($order['created_at'] ?? null),
            updatedAt: Timestamp::parse($order['updated_at'] ?? null),
            cancelledAt: Timestamp::parse($order['cancelled_at'] ?? null),
        );
    }

    /**
     * @param  array<int, string>  $protectionSkus
     * @param  array<int, string>  $protectionVendors
     * @return array<int, ShopifyLineItem>
     */
    public function protectionLineItems(array $protectionSkus, array $protectionVendors): array
    {
        return array_values(array_filter(
            $this->lineItems,
            fn (ShopifyLineItem $item): bool => $item->isProtection($protectionSkus, $protectionVendors),
        ));
    }
}
