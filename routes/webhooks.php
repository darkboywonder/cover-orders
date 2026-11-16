<?php

use App\Http\Controllers\Webhooks\RechargeWebhookController;
use App\Http\Controllers\Webhooks\ShopifyWebhookController;
use App\Http\Middleware\EnsureWebhookIsSecure;
use App\Http\Middleware\LogWebhookRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Registered outside the "web" group: no sessions, cookies or CSRF. Every
| request is authenticated by its HMAC signature instead.
|
*/

Route::prefix('webhooks')
    ->middleware([LogWebhookRequest::class, EnsureWebhookIsSecure::class])
    ->name('webhooks.')
    ->group(function (): void {
        Route::post('shopify', ShopifyWebhookController::class)->name('shopify');

        Route::post('recharge/{shopUuid}/{topic}', RechargeWebhookController::class)
            ->where('shopUuid', '[0-9a-fA-F-]{36}')
            ->where('topic', '[a-z_]+/[a-z_]+')
            ->name('recharge');
    });
