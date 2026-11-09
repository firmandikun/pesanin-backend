<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BroadcastRecipient extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'datetime'];

    public function broadcast()
    {
        return $this->belongsTo(Broadcast::class);
    }
}
