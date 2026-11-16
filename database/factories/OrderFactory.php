<?php

namespace Database\Factories;

use App\Enums\OrderOrigin;
use App\Models\Order;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shopifyOrderId = (string) fake()->unique()->numberBetween(5_000_000_000_000, 5_999_999_999_999);

        return [
            'shop_id' => Shop::factory(),
            'shopify_order_id' => $shopifyOrderId,
            'admin_graphql_api_id' => 'gid://shopify/Order/'.$shopifyOrderId,
            'name' => '#'.fake()->numberBetween(1000, 9999),
            'currency' => 'USD',
            'total_price_cents' => fake()->numberBetween(2_000, 20_000),
            'protection_price_cents' => 0,
            'has_protection_line' => false,
            'origin' => OrderOrigin::Checkout,
            'placed_at' => now(),
        ];
    }

    public function withProtection(int $priceCents = 295): static
    {
        return $this->state(fn (): array => [
            'has_protection_line' => true,
            'protection_price_cents' => $priceCents,
        ]);
    }
}
