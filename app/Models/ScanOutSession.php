<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanOutSession extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(ScanOutParcel::class);
    }

    /** The phone link (QR) still accepts scans. */
    public function isOpen(): bool
    {
        return $this->status === 'open' && $this->expires_at?->isFuture();
    }
}
