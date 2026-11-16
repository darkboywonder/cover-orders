<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Shop;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class RechargeWebhookControllerTest extends TestCase
{
    use LazilyRefreshDatabase, SendsWebhooks;

    public function test_signed_delivery_is_stored_with_topic_from_url_and_queued(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        $shop = Shop::factory()->create();

        $response = $this->postRechargeWebhook($shop, 'subscription/created', $this->fixture('recharge/subscription-created'));

        $response->assertOk()->assertExactJson(['status' => 'accepted']);
        $event = WebhookEvent::sole();
        $this->assertSame(WebhookSource::Recharge, $event->source);
        $this->assertSame('subscription/created', $event->topic);
        $this->assertSame($shop->id, $event->shop_id);
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_returns_401_when_signed_with_another_shops_secret(): void
    {
        $shop = Shop::factory()->create();
        $otherShop = Shop::factory()->create();
        $body = json_encode($this->fixture('recharge/subscription-created'));
        $timestamp = now()->getTimestamp();

        $response = $this->postRechargeWebhook($shop, 'subscription/created', $body, [
            'X-Recharge-Webhook-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $otherShop->recharge_webhook_secret),
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseEmpty('webhook_events');
    }

    public function test_returns_401_when_signed_timestamp_is_outside_the_tolerance_window(): void
    {
        $this->freezeTime();
        $shop = Shop::factory()->create();
        $staleTimestamp = now()->subSeconds(172_801)->getTimestamp();

        $response = $this->postRechargeWebhook($shop, 'subscription/created', $this->fixture('recharge/subscription-created'), timestamp: $staleTimestamp);

        $response->assertUnauthorized();
        $this->assertDatabaseEmpty('webhook_events');
    }

    public function test_accepts_a_retry_signed_within_the_two_day_window(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        $this->freezeTime();
        $shop = Shop::factory()->create();

        $response = $this->postRechargeWebhook($shop, 'subscription/created', $this->fixture('recharge/subscription-created'), timestamp: now()->subDay()->getTimestamp());

        $response->assertOk();
    }

    public function test_accepts_the_legacy_sha256_signature(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        $shop = Shop::factory()->create();
        $body = json_encode($this->fixture('recharge/subscription-created'));

        $response = $this->postRechargeWebhook($shop, 'subscription/created', $body, [
            'X-Recharge-Webhook-Signature' => '',
            'X-Recharge-Hmac-Sha256' => hash('sha256', $shop->recharge_webhook_secret.$body),
        ]);

        $response->assertOk();
    }

    public function test_rejects_the_legacy_signature_when_it_is_disabled(): void
    {
        config(['services.recharge.allow_legacy_signature' => false]);
        $shop = Shop::factory()->create();
        $body = json_encode($this->fixture('recharge/subscription-created'));

        $response = $this->postRechargeWebhook($shop, 'subscription/created', $body, [
            'X-Recharge-Webhook-Signature' => '',
            'X-Recharge-Hmac-Sha256' => hash('sha256', $shop->recharge_webhook_secret.$body),
        ]);

        $response->assertUnauthorized();
    }

    public function test_returns_404_for_an_unknown_shop(): void
    {
        $unknownShop = Shop::factory()->make(['uuid' => (string) Str::uuid()]);

        $response = $this->postRechargeWebhook($unknownShop, 'subscription/created', '{"subscription":{"id":1}}');

        $response->assertNotFound();
        $this->assertDatabaseEmpty('webhook_events');
    }

    public function test_same_resource_version_is_stored_once_but_a_newer_version_is_stored_again(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        $shop = Shop::factory()->create();
        $payload = $this->fixture('recharge/subscription-created');
        $newerPayload = $payload;
        $newerPayload['subscription']['updated_at'] = '2026-10-05T10:00:00+00:00';

        $this->postRechargeWebhook($shop, 'subscription/updated', $payload)->assertOk();
        $duplicate = $this->postRechargeWebhook($shop, 'subscription/updated', $payload);
        $this->postRechargeWebhook($shop, 'subscription/updated', $newerPayload)->assertOk();

        $duplicate->assertExactJson(['status' => 'duplicate']);
        $this->assertDatabaseCount('webhook_events', 2);
    }
}
