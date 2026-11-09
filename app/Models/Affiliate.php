<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Affiliate extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['is_active' => 'boolean', 'commission_percent' => 'float'];
}
