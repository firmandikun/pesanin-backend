<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffTimeOff extends Model
{
    protected $guarded = ['id', 'user_id'];

    public function staff(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CsStaff::class, 'cs_staff_id');
    }

    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
}
