<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAccount extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['is_active' => 'boolean', 'events' => 'array'];
}
