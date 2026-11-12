<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceShop extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_expires_at' => 'datetime',
            'ads_balance_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'products_synced_at' => 'datetime',
            'orders_synced_at' => 'datetime',
            'is_demo' => 'boolean',
            'ads_ppn' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
