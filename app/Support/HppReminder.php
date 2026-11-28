<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Pengingat HPP kosong: once a week, the Shopee variants that sold in the last 7 days but have no HPP, best sellers
 * first. Without HPP their sales count as 100% margin, so laba bersih looks higher than it is.
 */
class HppReminder
{
    public const URL = '/marketplace/products?cost=empty&bulk=1';

    /** @return array<int, array{name: string, sku: ?string, units: int}> */
    public static function missing(User $user, int $days = 7): array
    {
        $listings = MarketplaceListing::where('user_id', $user->id)->where(fn ($q) => $q->whereNull('cost_price')->orWhere('cost_price', 0))
            ->with('product:id,cost_price')->get(['id', 'marketplace_shop_id', 'product_id', 'item_id', 'model_id', 'name', 'model_name', 'sku'])
            ->reject(fn ($l) => (int) $l->product?->cost_price > 0);
        if ($listings->isEmpty()) {
            return [];
        }
        $sold = MarginCheck::sold($user, $listings->pluck('marketplace_shop_id')->unique()->values()->all(), $days);

        return $listings->map(fn ($l) => [
            'name' => trim($l->name.($l->model_name ? " ({$l->model_name})" : '')), 'sku' => $l->sku,
            'units' => $sold["{$l->marketplace_shop_id}:{$l->item_id}:".(int) $l->model_id]['units'] ?? 0,
        ])->where('units', '>', 0)->sortByDesc('units')->values()->all();
    }

    /** Weekly; at most once per ISO week. Returns the number of variants listed. */
    public static function notify(User $user): int
    {
        if (! Alert::enabled($user, 'missing_hpp')) {
            return 0;
        }
        $rows = self::missing($user);
        if (! $rows || ! Cache::add("hpp_reminder:{$user->id}:".now(Dates::TZ)->format('o-W'), 1, now()->addDays(8))) {
            return 0;
        }
        $units = array_sum(array_column($rows, 'units'));
        $lines = collect($rows)->take(8)->map(fn ($r) => '• '.mb_strimwidth($r['name'], 0, 48, '…').($r['sku'] ? " [{$r['sku']}]" : '')." · terjual {$r['units']}")->implode("\n");
        $more = count($rows) > 8 ? "\n+".(count($rows) - 8).' variasi lainnya' : '';
        Alert::send($user, 'hpp', count($rows)." produk terjual minggu ini belum ada HPP ({$units} pcs)",
            "Laba bersih dari produk ini terhitung terlalu tinggi.\n{$lines}{$more}", self::URL, '🧾', 'Isi cepat: Pesanin → Marketplace → Semua Produk → Isi HPP Sekarang');

        return count($rows);
    }
}
