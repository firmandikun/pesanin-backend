<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Daily snapshot of a Shopee product's views / likes / units sold (totals as Shopee reports them). */
class MarketplaceItemStat extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
