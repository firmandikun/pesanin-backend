<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw marketplace webhook log (Shopee Live Push). Orders themselves live in `orders`; once an event is applied
 * nothing reads it again, so applied or failed events older than MARKETPLACE_EVENTS_KEEP_DAYS (default 30) are
 * deleted daily by `model:prune`. Events still waiting to be applied are never pruned.
 */
class MarketplaceEvent extends Model
{
    use MassPrunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        $days = max(1, (int) config('services.marketplace_events_keep_days', 30));

        return static::where('created_at', '<', now()->subDays($days))
            ->where(fn (Builder $q) => $q->whereNotNull('processed_at')->orWhereNotNull('error'));
    }
}
