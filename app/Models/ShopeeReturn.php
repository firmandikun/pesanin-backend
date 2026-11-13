<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Shopee return / refund request. */
class ShopeeReturn extends Model
{
    protected $guarded = ['id'];

    /** Waiting for the seller (Seller Centre counts these on the Pengembalian/Pembatalan tab). */
    public const NEEDS_SELLER = ['REQUESTED', 'PROCESSING'];

    protected function casts(): array
    {
        return ['items' => 'array', 'due_at' => 'datetime', 'requested_at' => 'datetime'];
    }

    public function marketplaceShop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class);
    }
}
