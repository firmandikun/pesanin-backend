<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanOutParcel extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['matched' => 'boolean', 'scanned_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ScanOutSession::class, 'scan_out_session_id');
    }
}
