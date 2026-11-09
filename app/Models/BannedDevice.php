<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BannedDevice extends Model
{
    protected $guarded = ['id', 'user_id'];
}
