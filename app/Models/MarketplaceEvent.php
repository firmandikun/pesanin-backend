<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
