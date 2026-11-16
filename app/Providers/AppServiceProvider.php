<?php

namespace App\Providers;

use App\Coverage\CoverageService;
use App\Webhooks\Verification\RechargeSignatureVerifier;
use App\Webhooks\Verification\ShopifySignatureVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CoverageService::class, fn (): CoverageService => new CoverageService(
            config('coverage.billable_recharge_order_statuses'),
        ));

        $this->app->bind(ShopifySignatureVerifier::class, fn (): ShopifySignatureVerifier => new ShopifySignatureVerifier(
            config('services.shopify.webhook_secret'),
        ));

        $this->app->bind(RechargeSignatureVerifier::class, fn (): RechargeSignatureVerifier => new RechargeSignatureVerifier(
            (int) config('services.recharge.signature_tolerance_seconds'),
            (bool) config('services.recharge.allow_legacy_signature'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
