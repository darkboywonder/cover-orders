<?php

namespace App\Models;

use App\Enums\OrderOrigin;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Shopify order as we know it. The same order can be described by Shopify
 * (orders/*) and, for recurring orders, by Recharge (order/*); both converge on
 * this one row, keyed by shop + Shopify order id.
 */
#[Fillable([
    'shop_id', 'shopify_order_id', 'admin_graphql_api_id', 'name', 'currency', 'total_price_cents',
    'protection_price_cents', 'has_protection_line', 'origin', 'recharge_order_id', 'recharge_status',
    'subscription_id', 'placed_at', 'cancelled_at', 'source_updated_at', 'recharge_updated_at',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => OrderOrigin::class,
            'has_protection_line' => 'boolean',
            'total_price_cents' => 'integer',
            'protection_price_cents' => 'integer',
            'placed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'source_updated_at' => 'immutable_datetime',
            'recharge_updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasOne<Coverage, $this>
     */
    public function coverage(): HasOne
    {
        return $this->hasOne(Coverage::class);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
