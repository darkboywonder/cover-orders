<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $handle = Str::slug(fake()->unique()->company());

        return [
            'shopify_domain' => $handle.'.myshopify.com',
            'name' => Str::headline($handle),
            'recharge_webhook_secret' => Str::random(40),
        ];
    }
}
