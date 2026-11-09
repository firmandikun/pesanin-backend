<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A seller's own Biteship / Midtrans account. `credentials` is encrypted at rest and never sent to the browser. */
class UserIntegration extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_production' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function key(string $name): ?string
    {
        return $this->credentials[$name] ?? null;
    }
}
