<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaSpyAd extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['platforms' => 'array', 'media_urls' => 'array', 'is_active' => 'boolean', 'collation_count' => 'integer',
            'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /** Days on air: from start_date to end_date (stopped ads) or today. */
    public function getDaysRunningAttribute(): int
    {
        if (! $this->start_date) {
            return 0;
        }
        $end = $this->is_active || ! $this->end_date ? now() : $this->end_date;

        return max(0, (int) $this->start_date->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()));
    }
}
