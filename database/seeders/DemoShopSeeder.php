<?php

namespace Database\Seeders;

use App\Models\Shop;
use Illuminate\Database\Seeder;

/**
 * Creates the store used by `php artisan webhooks:simulate`. The Recharge secret is a
 * local demo value; in production each store's secret comes from its Recharge integration.
 */
class DemoShopSeeder extends Seeder
{
    public const DOMAIN = 'demo-store.myshopify.com';

    public const RECHARGE_SECRET = 'local-demo-recharge-secret';

    public function run(): void
    {
        $shop = Shop::updateOrCreate(
            ['shopify_domain' => self::DOMAIN],
            ['name' => 'Demo Store', 'recharge_webhook_secret' => self::RECHARGE_SECRET],
        );

        $this->command?->info('Demo shop: '.$shop->shopify_domain);
        $this->command?->info('Shopify webhook URL:  '.route('webhooks.shopify'));
        $this->command?->info('Recharge webhook URL: '.str_replace(
            'resource/event',
            '{topic}',
            route('webhooks.recharge', ['shopUuid' => $shop->uuid, 'topic' => 'resource/event']),
        ));
    }
}
