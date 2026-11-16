<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Shop;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'recharge_subscription_id' => (string) fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'recharge_customer_id' => (string) fake()->numberBetween(10_000_000, 99_999_999),
            'status' => SubscriptionStatus::Active,
            'is_covered' => false,
            'order_interval_unit' => 'month',
            'order_interval_frequency' => 1,
        ];
    }

    public function covered(int $premiumCents = 295, string $currency = 'USD'): static
    {
        return $this->state(fn (): array => [
            'is_covered' => true,
            'covered_premium_cents' => $premiumCents,
            'currency' => $currency,
        ]);
    }
}
