<?php

namespace App\Webhooks\Payloads;

use App\Support\Money;

/**
 * One entry of a Shopify order's line_items (REST webhook serialization).
 */
final readonly class ShopifyLineItem
{
    public function __construct(
        public ?string $sku,
        public ?string $vendor,
        public ?string $title,
        public int $priceCents,
        public int $quantity,
        public int $discountCents,
    ) {}

    /**
     * @param  array<string, mixed>  $lineItem
     */
    public static function fromArray(array $lineItem): self
    {
        $discountCents = collect($lineItem['discount_allocations'] ?? [])
            ->sum(fn (array $allocation): int => Money::toCents($allocation['amount'] ?? null));

        return new self(
            sku: isset($lineItem['sku']) ? (string) $lineItem['sku'] : null,
            vendor: isset($lineItem['vendor']) ? (string) $lineItem['vendor'] : null,
            title: isset($lineItem['title']) ? (string) $lineItem['title'] : null,
            priceCents: Money::toCents($lineItem['price'] ?? null),
            quantity: (int) ($lineItem['quantity'] ?? 0),
            discountCents: $discountCents,
        );
    }

    /**
     * @param  array<int, string>  $protectionSkus
     * @param  array<int, string>  $protectionVendors
     */
    public function isProtection(array $protectionSkus, array $protectionVendors): bool
    {
        if ($this->sku !== null && in_array(strtoupper($this->sku), array_map('strtoupper', $protectionSkus), true)) {
            return true;
        }

        return $this->vendor !== null && in_array(strtolower($this->vendor), array_map('strtolower', $protectionVendors), true);
    }

    public function netCents(): int
    {
        return max(0, $this->priceCents * $this->quantity - $this->discountCents);
    }
}
