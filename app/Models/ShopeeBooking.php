<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Shopee "Pengiriman Kilat" reservation (booking). Not a sale: it never counts toward revenue. */
class ShopeeBooking extends Model
{
    protected $guarded = ['id'];

    /** Booking statuses still waiting in Seller Centre's "Perlu Dikirim". */
    public const OPEN = ['READY_TO_SHIP', 'PROCESSED'];

    protected function casts(): array
    {
        return ['items' => 'array', 'booked_at' => 'datetime'];
    }

    public function marketplaceShop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class);
    }
}
