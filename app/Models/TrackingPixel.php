<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingPixel extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['server_side' => 'boolean'];
}
