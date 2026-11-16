<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Recharge subscription. It is covered when the original order the customer
 * placed (origin order) is covered, and passes that coverage on to the
 * recurring orders Recharge places for it.
 */
#[Fillable([
    'shop_id', 'recharge_subscription_id', 'recharge_customer_id', 'recharge_address_id', 'status',
    'origin_shopify_order_id', 'origin_order_id', 'is_covered', 'covered_premium_cents', 'currency',
    'external_product_id', 'sku', 'order_interval_unit', 'order_interval_frequency',
    'next_charge_scheduled_at', 'cancelled_at', 'source_updated_at',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'is_covered' => 'boolean',
            'covered_premium_cents' => 'integer',
            'order_interval_frequency' => 'integer',
            'next_charge_scheduled_at' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
            'source_updated_at' => 'immutable_datetime',
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
    public function originOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'origin_order_id');
    }

    /**
     * Recurring orders Recharge placed for this subscription.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Whether an order placed at the given moment is covered by this subscription.
     * Cancelling never reaches back: orders placed before the cancellation stay covered.
     */
    public function coversOrderPlacedAt(?CarbonInterface $placedAt): bool
    {
        if (! $this->is_covered || $this->covered_premium_cents === null) {
            return false;
        }

        if ($this->cancelled_at !== null) {
            return $placedAt !== null && $placedAt->lessThan($this->cancelled_at);
        }

        return $this->status === SubscriptionStatus::Active;
    }
}
