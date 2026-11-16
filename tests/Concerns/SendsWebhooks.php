<?php

namespace Tests\Concerns;

use App\Models\Shop;
use App\Webhooks\Verification\RechargeSignatureVerifier;
use App\Webhooks\Verification\ShopifySignatureVerifier;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Signs fixture payloads the way Shopify and Recharge do and posts them to the webhook endpoints.
 */
trait SendsWebhooks
{
    protected string $shopifySecret = 'test-shopify-secret';

    /**
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $headers
     */
    protected function postShopifyWebhook(Shop $shop, string $topic, array|string $payload, array $headers = []): TestResponse
    {
        config(['services.shopify.webhook_secret' => $this->shopifySecret]);

        $body = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/webhooks/shopify', server: $this->serverHeaders([
            'X-Shopify-Topic' => $topic,
            'X-Shopify-Shop-Domain' => $shop->shopify_domain,
            'X-Shopify-Hmac-Sha256' => ShopifySignatureVerifier::sign($body, $this->shopifySecret),
            'X-Shopify-Webhook-Id' => (string) Str::uuid(),
            'X-Shopify-Event-Id' => (string) Str::uuid(),
            ...$headers,
        ]), content: $body);
    }

    /**
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $headers
     */
    protected function postRechargeWebhook(Shop $shop, string $topic, array|string $payload, array $headers = [], ?int $timestamp = null): TestResponse
    {
        $body = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();

        return $this->call('POST', "/webhooks/recharge/{$shop->uuid}/{$topic}", server: $this->serverHeaders([
            'X-Recharge-Webhook-Timestamp' => (string) $timestamp,
            'X-Recharge-Webhook-Signature' => RechargeSignatureVerifier::sign($body, (string) $shop->recharge_webhook_secret, $timestamp),
            ...$headers,
        ]), content: $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
