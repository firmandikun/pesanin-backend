<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeAdStat extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
