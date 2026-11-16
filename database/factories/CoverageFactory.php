<?php

namespace Database\Factories;

use App\Enums\CoverageReason;
use App\Enums\CoverageStatus;
use App\Models\Coverage;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coverage>
 */
class CoverageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->withProtection(),
            'shop_id' => fn (array $attributes): int => Order::find($attributes['order_id'])->shop_id,
            'reason' => CoverageReason::ProtectionLine,
            'premium_cents' => 295,
            'currency' => 'USD',
            'status' => CoverageStatus::Active,
            'covered_at' => now(),
        ];
    }
}
