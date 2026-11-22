<?php

namespace App\Support;

use App\Models\StockMaster;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Prediksi stok: how fast each Stok Master SKU sells (sales recorded on the master, last 30 days), when it runs
 * out, and how many to restock so it lasts the supplier's lead time plus a cover period. Also flags dead stock
 * (stock but no sale in 30 days): money sitting in the warehouse.
 */
class RestockForecast
{
    public const KEY = 'restock';

    public const WINDOW = 30;

    public static function settings(User $user): array
    {
        $s = (array) ($user->settings[self::KEY] ?? []);

        return ['lead_days' => max(1, min(90, (int) ($s['lead_days'] ?? 7))), 'cover_days' => max(1, min(120, (int) ($s['cover_days'] ?? 14)))];
    }

    /** Units sold per master in the last 30 days (sales minus cancellations). @return array<int,int> */
    public static function sold(array $masterIds): array
    {
        if (! $masterIds) {
            return [];
        }

        return StockMovement::whereIn('stock_master_id', $masterIds)->whereIn('kind', ['sale', 'restore'])
            ->where('created_at', '>=', now()->subDays(self::WINDOW))
            ->selectRaw('stock_master_id, -SUM(qty) as units')->groupBy('stock_master_id')->pluck('units', 'stock_master_id')
            ->map(fn ($v) => max(0, (int) $v))->all();
    }

    /** Forecast for one master. */
    public static function forecast(StockMaster $m, int $sold30, array $cfg): array
    {
        // A master tracked for less than 30 days: average over the days it has been tracked.
        $since = $m->tracking_since ?? $m->created_at;
        $days = max(1, min(self::WINDOW, (int) ceil($since ? $since->diffInDays(now(), true) : self::WINDOW)));
        $perDay = $sold30 / $days;
        $stock = (int) $m->stock;
        $daysLeft = $perDay > 0 ? (int) floor(max(0, $stock) / $perDay) : null;
        $need = (int) ceil($perDay * ($cfg['lead_days'] + $cfg['cover_days'])) - max(0, $stock);
        $status = match (true) {
            $perDay <= 0 => $stock > 0 && $days >= 14 ? 'mati' : 'tanpa_data',
            $stock <= 0 => 'habis',
            $daysLeft <= $cfg['lead_days'] => 'kritis',
            $daysLeft <= $cfg['lead_days'] + 7 => 'segera',
            default => 'aman',
        };

        return [
            'sold_30d' => $sold30, 'per_day' => round($perDay, 2), 'days_left' => $daysLeft,
            'run_out_at' => $daysLeft !== null ? now()->addDays($daysLeft)->toDateString() : null,
            'restock_qty' => in_array($status, ['habis', 'kritis', 'segera'], true) ? max(0, $need) : 0,
            'status' => $status,
        ];
    }

    /** Counts for the Stok Master cards. */
    public static function summary(User $user): array
    {
        $cfg = self::settings($user);
        $out = ['habis' => 0, 'kritis' => 0, 'segera' => 0, 'mati' => 0, 'dead_units' => 0, 'dead_value' => 0] + $cfg;
        StockMaster::where('user_id', $user->id)->with(['listings' => fn ($l) => $l->select('id', 'stock_master_id', 'product_id', 'cost_price')->with('product:id,cost_price')])
            ->chunk(500, function ($masters) use (&$out, $cfg) {
                $sold = self::sold($masters->pluck('id')->all());
                foreach ($masters as $m) {
                    $f = self::forecast($m, $sold[$m->id] ?? 0, $cfg);
                    if (isset($out[$f['status']])) {
                        $out[$f['status']]++;
                    }
                    if ($f['status'] === 'mati') {
                        $hpp = $m->listings->map(fn ($l) => (int) ($l->cost_price ?: $l->product?->cost_price))->filter()->max() ?? 0;
                        $out['dead_units'] += $m->stock;
                        $out['dead_value'] += $m->stock * $hpp;
                    }
                }
            });

        return $out;
    }

    /** Daily: tell the seller which SKUs newly need restocking (each SKU once until it is restocked). */
    public static function notify(User $user): int
    {
        $cfg = self::settings($user);
        $key = "restock_alert:{$user->id}";
        $seen = Cache::get($key, []);
        $now = [];
        $new = [];
        StockMaster::where('user_id', $user->id)->chunk(500, function ($masters) use ($cfg, $seen, &$now, &$new) {
            $sold = self::sold($masters->pluck('id')->all());
            foreach ($masters as $m) {
                $f = self::forecast($m, $sold[$m->id] ?? 0, $cfg);
                if (! in_array($f['status'], ['habis', 'kritis'], true)) {
                    continue;
                }
                $now[] = $m->id;
                if (! in_array($m->id, $seen, true)) {
                    $new[] = '• '.mb_strimwidth($m->name ?: $m->sku, 0, 40, '…')." ({$m->sku}): ".($f['status'] === 'habis' ? 'HABIS' : "sisa {$m->stock}, habis ±{$f['days_left']} hari")." · restock {$f['restock_qty']} pcs";
                }
            }
        });
        Cache::put($key, $now, now()->addDays(60));
        if (! $new) {
            return 0;
        }
        Notifier::send($user->id, 'restock', count($new).' SKU perlu restock', implode("\n", array_slice($new, 0, 10)).(count($new) > 10 ? "\n+".(count($new) - 10).' lainnya' : ''), '/marketplace/stock?filter=restock');

        return count($new);
    }
}
