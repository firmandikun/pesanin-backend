<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['exclusive' => 'boolean', 'once_per_customer' => 'boolean', 'is_active' => 'boolean', 'product_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
}
