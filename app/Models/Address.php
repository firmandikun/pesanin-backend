<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['is_default' => 'boolean'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
