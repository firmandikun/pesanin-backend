<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaRotator extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['staff_ids' => 'array', 'settings' => 'array'];
}
