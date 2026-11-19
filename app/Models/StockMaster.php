<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockMaster extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['tracking_since' => 'datetime', 'pushed_at' => 'datetime', 'push_pending' => 'boolean'];

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
