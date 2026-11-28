<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['qty' => 'integer', 'unit_cost' => 'integer', 'landed_unit_cost' => 'integer'];

    public function stockMaster(): BelongsTo
    {
        return $this->belongsTo(StockMaster::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
