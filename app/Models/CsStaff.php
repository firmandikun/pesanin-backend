<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CsStaff extends Model
{
    protected $table = 'cs_staff';

    protected $guarded = ['id', 'user_id'];

    protected $casts = ['is_active' => 'boolean', 'schedule' => 'array'];
}
