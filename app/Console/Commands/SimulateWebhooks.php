<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Webhooks\Verification\RechargeSignatureVerifier;
use App\Webhooks\Verification\ShopifySignatureVerifier;
use Database\Seeders\DemoShopSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Signature('webhooks:simulate
    {scenario=covered-subscription : covered-subscription, out-of-order or uncovered-subscription}
    {--shop='.DemoShopSeeder::DOMAIN.' : The shop to send as}
    {--repeat=1 : Send every delivery this many times, as a platform retry would}
    {--url= : POST to a running server at this base URL instead of handling requests in-process}')]
#[Description('Sign the Shopify and Recharge fixtures and deliver them as webhooks')]
class SimulateWebhooks extends Command
{
    /**
     * Each step is [source, topic, fixture]. Fixtures live in tests/Fixtures and follow
     * the documented Shopify Order and Recharge 2021-11 Order/Subscription payloads.
     *
     * @var array<string, array<int, array{0: string, 1: string, 2: string}>>
     */
    private const SCENARIOS = [
        'covered-subscription' => [
            ['shopify', 'orders/create', 'shopify/orders-create-covered'],
            ['recharge', 'subscription/created', 'recharge/subscription-created'],
            ['recharge', 'order/created', 'recharge/order-checkout'],
            ['recharge', 'order/created', 'recharge/order-recurring-queued'],
            ['recharge', 'order/processed', 'recharge/order-recurring-success'],
            ['shopify', 'orders/create', 'shopify/orders-create-recurring'],
            ['recharge', 'subscription/cancelled', 'recharge/subscription-cancelled'],
        ],
        'out-of-order' => [
            ['shopify', 'orders/create', 'shopify/orders-create-recurring'],
            ['recharge', 'order/processed', 'recharge/order-recurring-success'],
            ['recharge', 'order/created', 'recharge/order-checkout'],
            ['recharge', 'subscription/created', 'recharge/subscription-created'],
            ['shopify', 'orders/create', 'shopify/orders-create-covered'],
        ],
        'uncovered-subscription' => [
            ['shopify', 'orders/create', 'shopify/orders-create-uncovered'],
            ['recharge', 'subscription/created', 'recharge/subscription-created-uncovered'],
            ['recharge', 'order/created', 'recharge/order-checkout-uncovered'],
        ],
    ];

    public function handle(): int
    {
        $steps = self::SCENARIOS[$this->argument('scenario')] ?? null;

        if ($steps === null) {
            $this->components->error('Unknown scenario. Choose one of: '.implode(', ', array_keys(self::SCENARIOS)));

            return self::FAILURE;
        }

        $shop = Shop::where('shopify_domain', $this->option('shop'))->first();

        if ($shop === null) {
            $this->components->error('Shop not found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        if (! $this->option('url')) {
            // In-process mode processes each event immediately so the result is visible right away.
            config(['queue.default' => 'sync']);
        }

        foreach ($steps as [$source, $topic, $fixture]) {
            $body = (string) file_get_contents(base_path("tests/Fixtures/{$fixture}.json"));

            for ($attempt = 1; $attempt <= (int) $this->option('repeat'); $attempt++) {
                [$path, $headers] = $source === 'shopify'
                    ? $this->shopifyDelivery($shop, $topic, $body, $fixture)
                    : $this->rechargeDelivery($shop, $topic, $body);

                [$status, $response] = $this->deliver($path, $headers, $body);

                $this->components->twoColumnDetail("{$source} {$topic} <fg=gray>{$fixture}</>", "{$status} {$response}");
            }
        }

        $this->newLine();
        $this->call('coverage:report', ['shop' => $shop->shopify_domain]);

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function shopifyDelivery(Shop $shop, string $topic, string $body, string $fixture): array
    {
        return ['/webhooks/shopify', [
            'X-Shopify-Topic' => $topic,
            'X-Shopify-Shop-Domain' => $shop->shopify_domain,
            'X-Shopify-Hmac-Sha256' => ShopifySignatureVerifier::sign($body, (string) config('services.shopify.webhook_secret')),
            // Stable per fixture, so --repeat behaves like a Shopify retry of the same delivery.
            'X-Shopify-Webhook-Id' => hash('sha256', $shop->shopify_domain.'|'.$topic.'|'.$fixture),
            'X-Shopify-Event-Id' => (string) Str::uuid(),
            'X-Shopify-Triggered-At' => now()->toIso8601ZuluString(),
            'X-Shopify-API-Version' => '2026-10',
        ]];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function rechargeDelivery(Shop $shop, string $topic, string $body): array
    {
        $timestamp = now()->getTimestamp();

        return ["/webhooks/recharge/{$shop->uuid}/{$topic}", [
            'X-Recharge-Webhook-Timestamp' => (string) $timestamp,
            'X-Recharge-Webhook-Signature' => RechargeSignatureVerifier::sign($body, (string) $shop->recharge_webhook_secret, $timestamp),
            'X-Recharge-External-Platform-Domain' => $shop->shopify_domain,
        ]];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: string}
     */
    private function deliver(string $path, array $headers, string $body): array
    {
        if ($baseUrl = $this->option('url')) {
            $response = Http::withHeaders($headers)->withBody($body, 'application/json')->post(rtrim($baseUrl, '/').$path);

            return [$response->status(), $response->body()];
        }

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $kernel = app(Kernel::class);
        $request = Request::create($path, 'POST', server: $server, content: $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return [$response->getStatusCode(), (string) $response->getContent()];
    }
}
