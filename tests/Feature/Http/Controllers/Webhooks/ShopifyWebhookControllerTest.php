<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Enums\WebhookEventStatus;
use App\Enums\WebhookSource;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Shop;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class ShopifyWebhookControllerTest extends TestCase
{
    use LazilyRefreshDatabase, SendsWebhooks;

    public function test_signed_delivery_is_stored_encrypted_and_queued(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        $shop = Shop::factory()->create();
        $payload = $this->fixture('shopify/orders-create-covered');

        $response = $this->postShopifyWebhook($shop, 'orders/create', $payload, ['X-Shopify-Webhook-Id' => 'delivery-1']);

        $response->assertOk()->assertExactJson(['status' => 'accepted']);
        $event = WebhookEvent::sole();
        $this->assertSame(WebhookSource::Shopify, $event->source);
        $this->assertSame('orders/create', $event->topic);
        $this->assertSame('delivery-1', $event->dedup_key);
        $this->assertSame(WebhookEventStatus::Received, $event->status);
        $this->assertSame($payload, $event->decodedPayload());
        $this->assertStringNotContainsString('jordan.rivera@example.com', $event->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('x-shopify-hmac-sha256', $event->headers);
        Queue::assertPushed(ProcessWebhookEvent::class, fn (ProcessWebhookEvent $job): bool => $job->event->is($event));
    }

    public function test_returns_401_and_stores_nothing_when_signature_does_not_match(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        $shop = Shop::factory()->create();

        $response = $this->postShopifyWebhook($shop, 'orders/create', $this->fixture('shopify/orders-create-covered'), [
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', 'tampered', $this->shopifySecret, true)),
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseEmpty('webhook_events');
        Queue::assertNotPushed(ProcessWebhookEvent::class);
    }

    public function test_returns_401_when_signature_header_is_missing(): void
    {
        $shop = Shop::factory()->create();

        $response = $this->postShopifyWebhook($shop, 'orders/create', '{"id":1}', ['X-Shopify-Hmac-Sha256' => '']);

        $response->assertUnauthorized();
        $this->assertDatabaseEmpty('webhook_events');
    }

    public function test_returns_400_when_topic_header_is_missing(): void
    {
        $shop = Shop::factory()->create();

        $response = $this->postShopifyWebhook($shop, '', '{"id":1}');

        $response->assertBadRequest();
        $this->assertDatabaseEmpty('webhook_events');
    }

    public function test_retried_delivery_is_stored_once_and_queued_once(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        $shop = Shop::factory()->create();
        $payload = $this->fixture('shopify/orders-create-covered');

        $this->postShopifyWebhook($shop, 'orders/create', $payload, ['X-Shopify-Webhook-Id' => 'delivery-1'])->assertOk();
        $response = $this->postShopifyWebhook($shop, 'orders/create', $payload, ['X-Shopify-Webhook-Id' => 'delivery-1']);

        $response->assertOk()->assertExactJson(['status' => 'duplicate']);
        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushedTimes(ProcessWebhookEvent::class, 1);
    }

    public function test_creates_the_shop_on_its_first_signed_delivery(): void
    {
        Queue::fake([ProcessWebhookEvent::class]);
        $shop = Shop::factory()->make(['shopify_domain' => 'new-store.myshopify.com']);

        $this->postShopifyWebhook($shop, 'orders/create', '{"id":1}')->assertOk();

        $this->assertDatabaseHas('shops', ['shopify_domain' => 'new-store.myshopify.com']);
    }

    public function test_returns_403_for_plain_http_in_production(): void
    {
        $this->app['env'] = 'production';
        $shop = Shop::factory()->create();

        $response = $this->postShopifyWebhook($shop, 'orders/create', '{"id":1}');

        $response->assertForbidden();
        $this->assertDatabaseEmpty('webhook_events');
    }
}
