<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopeeReview extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['media' => 'array', 'hidden' => 'boolean', 'replied_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class, 'marketplace_shop_id');
    }
}
