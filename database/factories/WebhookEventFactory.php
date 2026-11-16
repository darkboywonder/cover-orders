<?php

namespace Database\Factories;

use App\Enums\WebhookEventStatus;
use App\Enums\WebhookSource;
use App\Models\Shop;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => WebhookSource::Shopify,
            'topic' => 'orders/create',
            'shop_id' => Shop::factory(),
            'dedup_key' => (string) Str::uuid(),
            'external_event_id' => (string) Str::uuid(),
            'payload' => json_encode(['id' => fake()->randomNumber(9)]),
            'headers' => [],
            'status' => WebhookEventStatus::Received,
            'attempts' => 0,
            'received_at' => now(),
        ];
    }

    public function failed(string $error = 'RuntimeException: boom'): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookEventStatus::Failed,
            'attempts' => 5,
            'last_error' => $error,
        ]);
    }
}
