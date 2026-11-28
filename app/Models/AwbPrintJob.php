<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AwbPrintJob extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['order_ids' => 'array', 'booking_ids' => 'array', 'skipped' => 'array', 'errors' => 'array', 'finished_at' => 'datetime',
            'total' => 'integer', 'done' => 'integer', 'pages' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
