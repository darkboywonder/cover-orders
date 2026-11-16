<?php

namespace App\Models;

use App\Enums\CoverageReason;
use App\Enums\CoverageStatus;
use Database\Factories\CoverageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record we bill the store from: one row per covered Shopify order.
 */
#[Fillable([
    'shop_id', 'order_id', 'subscription_id', 'reason', 'premium_cents', 'currency',
    'status', 'covered_at', 'voided_at', 'void_reason',
])]
class Coverage extends Model
{
    /** @use HasFactory<CoverageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => CoverageReason::class,
            'status' => CoverageStatus::class,
            'premium_cents' => 'integer',
            'covered_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @param  Builder<Coverage>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CoverageStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === CoverageStatus::Active;
    }
}
