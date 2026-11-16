<?php

namespace App\Models;

use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['shopify_domain', 'name', 'recharge_webhook_secret'])]
#[Hidden(['recharge_webhook_secret'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, HasUuids;

    /**
     * The uuid is a public identifier used in webhook URLs; the primary key stays an integer.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recharge_webhook_secret' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<Coverage, $this>
     */
    public function coverages(): HasMany
    {
        return $this->hasMany(Coverage::class);
    }
}
