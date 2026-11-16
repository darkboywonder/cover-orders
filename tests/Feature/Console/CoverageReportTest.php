<?php

namespace Tests\Feature\Console;

use App\Enums\CoverageStatus;
use App\Models\Coverage;
use App\Models\Order;
use App\Models\Shop;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CoverageReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_totals_only_active_coverages_for_the_requested_month(): void
    {
        $shop = Shop::factory()->create(['shopify_domain' => 'report-store.myshopify.com']);
        $order = fn (): Order => Order::factory()->for($shop)->withProtection()->create();
        Coverage::factory()->for($order())->create(['shop_id' => $shop->id, 'premium_cents' => 295, 'covered_at' => '2026-11-03']);
        Coverage::factory()->for($order())->create(['shop_id' => $shop->id, 'premium_cents' => 350, 'covered_at' => '2026-11-20']);
        Coverage::factory()->for($order())->create(['shop_id' => $shop->id, 'status' => CoverageStatus::Voided, 'covered_at' => '2026-11-04']);
        Coverage::factory()->for($order())->create(['shop_id' => $shop->id, 'premium_cents' => 999, 'covered_at' => '2026-10-31']);

        $this->artisan('coverage:report', ['shop' => 'report-store.myshopify.com', '--month' => '2026-11'])
            ->expectsOutputToContain('Billable: 2 active coverage(s) of 3 record(s).')
            ->expectsOutputToContain('6.45 USD')
            ->assertSuccessful();
    }

    public function test_fails_for_an_unknown_shop(): void
    {
        $this->artisan('coverage:report', ['shop' => 'missing.myshopify.com'])->assertFailed();
    }
}
