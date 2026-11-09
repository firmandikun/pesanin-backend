<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomDomain extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['is_default' => 'boolean'];
}
