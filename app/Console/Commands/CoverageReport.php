<?php

namespace App\Console\Commands;

use App\Models\Coverage;
use App\Models\Shop;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('coverage:report
    {shop : The shop\'s myshopify.com domain}
    {--month= : Limit to coverages whose order was placed in this month (YYYY-MM)}')]
#[Description('Show the coverage records a shop would be billed from')]
class CoverageReport extends Command
{
    public function handle(): int
    {
        $shop = Shop::where('shopify_domain', $this->argument('shop'))->first();

        if ($shop === null) {
            $this->components->error("Unknown shop [{$this->argument('shop')}].");

            return self::FAILURE;
        }

        $query = $shop->coverages()->with('order', 'subscription')->orderBy('covered_at');

        if ($month = $this->option('month')) {
            $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
            $query->whereBetween('covered_at', [$start, $start->endOfMonth()]);
        }

        $coverages = $query->get();

        $this->table(
            ['Order', 'Shopify id', 'Reason', 'Subscription', 'Premium', 'Status', 'Covered at'],
            $coverages->map(fn (Coverage $coverage): array => [
                $coverage->order->name,
                $coverage->order->shopify_order_id,
                $coverage->reason->value,
                $coverage->subscription?->recharge_subscription_id ?? '-',
                Money::format($coverage->premium_cents, $coverage->currency),
                $coverage->status->value.($coverage->void_reason ? " ({$coverage->void_reason})" : ''),
                $coverage->covered_at->toDateTimeString(),
            ]),
        );

        $billable = $coverages->filter(fn (Coverage $coverage): bool => $coverage->isActive());

        $this->components->info("Billable: {$billable->count()} active coverage(s) of {$coverages->count()} record(s).");

        $billable->groupBy('currency')->each(function ($group, string $currency): void {
            $this->components->twoColumnDetail("Total premium {$currency}", Money::format($group->sum('premium_cents'), $currency));
        });

        $billable->groupBy(fn (Coverage $coverage): string => $coverage->reason->value)->each(function ($group, string $reason): void {
            $this->components->twoColumnDetail("  {$reason}", (string) $group->count());
        });

        return self::SUCCESS;
    }
}
