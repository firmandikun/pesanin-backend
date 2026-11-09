<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPage extends Model
{
    protected $guarded = ['id', 'user_id', 'views'];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'seo' => 'array',
            'settings' => 'array',
            'blocks' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
