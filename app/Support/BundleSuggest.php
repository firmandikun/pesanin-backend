<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;

/**
 * Saran Paket: products that buyers often put in the same Shopee order, from the shop's own order history.
 * Each pair can become a Paket Diskon (bundle deal) in one click, to raise the order value.
 *   together   = orders that contain both products
 *   confidence = together / orders of the less-sold product (how often one comes with the other)
 *   lift       = how much more often they come together than by chance (> 1 = real link)
 * Only pairs within one shop (a bundle deal is per shop) whose products are still on sale.
 */
class BundleSuggest
{
    public const DAYS = 90;

    public const MIN_TOGETHER = 2;

    public const LIMIT = 40;

    /** @return array{pairs: array<int, array>, summary: array{orders: int, multi_item_orders: int, days: int}} */
    public static function compute(User $user, ?int $shopId = null, int $days = self::DAYS): array
    {
        $shops = MarketplaceShop::where('user_id', $user->id)->where('platform', 'shopee')->where('status', '!=', 'disconnected')
            ->when($shopId, fn ($q) => $q->whereKey($shopId))->pluck('shop_name', 'id');

        $orders = 0;
        $multi = 0;
        $itemOrders = [];   // "shop:item" => orders containing it
        $pairs = [];        // "shop:itemA:itemB" (A < B) => orders containing both
        $perShop = [];      // shop => orders
        Order::where('user_id', $user->id)->whereIn('marketplace_shop_id', $shops->keys())->where('channel', 'shopee')
            ->where('created_at', '>=', now()->subDays($days))->whereNotIn('status', ['cancelled', 'refunded'])
            ->where(fn ($w) => $w->whereNull('external_status')->orWhereNotIn('external_status', ['CANCELLED', 'IN_CANCEL']))
            ->select(['id', 'marketplace_shop_id', 'items'])
            ->chunkById(1000, function ($chunk) use (&$orders, &$multi, &$itemOrders, &$pairs, &$perShop) {
                foreach ($chunk as $o) {
                    $ids = collect($o->items ?? [])->map(fn ($it) => (int) ($it['item_id'] ?? 0))->filter()->unique()->sort()->values()->all();
                    if (! $ids) {
                        continue;
                    }
                    $orders++;
                    $sid = (int) $o->marketplace_shop_id;
                    $perShop[$sid] = ($perShop[$sid] ?? 0) + 1;
                    foreach ($ids as $i) {
                        $itemOrders["{$sid}:{$i}"] = ($itemOrders["{$sid}:{$i}"] ?? 0) + 1;
                    }
                    // Very large baskets (resellers) would add noise and lots of pairs: only the first 12 products.
                    $ids = array_slice($ids, 0, 12);
                    if (count($ids) < 2) {
                        continue;
                    }
                    $multi++;
                    for ($a = 0; $a < count($ids); $a++) {
                        for ($b = $a + 1; $b < count($ids); $b++) {
                            $k = "{$sid}:{$ids[$a]}:{$ids[$b]}";
                            $pairs[$k] = ($pairs[$k] ?? 0) + 1;
                        }
                    }
                }
            });

        $pairs = array_filter($pairs, fn ($n) => $n >= self::MIN_TOGETHER);
        arsort($pairs);
        $pairs = array_slice($pairs, 0, self::LIMIT * 3, true);

        // Products still on sale, with their variations (to prefill the bundle form).
        $keys = collect(array_keys($pairs))->flatMap(function ($k) {
            [$s, $a, $b] = array_map('intval', explode(':', $k));

            return [[$s, $a], [$s, $b]];
        });
        $listings = $keys->isEmpty() ? collect() : MarketplaceListing::where('user_id', $user->id)->where('status', 'NORMAL')
            ->whereIn('marketplace_shop_id', $keys->pluck(0)->unique())->whereIn('item_id', $keys->pluck(1)->unique())
            ->get(['marketplace_shop_id', 'item_id', 'model_id', 'name', 'model_name', 'price', 'stock', 'image'])
            ->groupBy(fn ($l) => "{$l->marketplace_shop_id}:{$l->item_id}");
        $item = function (int $s, int $i) use ($listings, $itemOrders) {
            $ls = $listings["{$s}:{$i}"] ?? null;
            if (! $ls || $ls->isEmpty()) {
                return null;
            }
            $f = $ls->first();

            return [
                'item_id' => $i, 'name' => $f->name, 'image' => $f->image, 'orders' => $itemOrders["{$s}:{$i}"] ?? 0,
                'price' => (int) $ls->min('price'), 'price_max' => (int) $ls->max('price'),
                'models' => $ls->map(fn ($l) => ['model_id' => (int) $l->model_id, 'name' => $l->model_name, 'price' => (int) $l->price, 'stock' => (int) $l->stock])->values()->all(),
            ];
        };

        $out = [];
        foreach ($pairs as $k => $n) {
            [$s, $a, $b] = array_map('intval', explode(':', $k));
            $A = $item($s, $a);
            $B = $item($s, $b);
            if (! $A || ! $B) {
                continue;
            }
            $total = max(1, $perShop[$s] ?? 1);
            $lift = $A['orders'] && $B['orders'] ? round($n * $total / ($A['orders'] * $B['orders']), 1) : 0;
            $out[] = [
                'key' => $k, 'shop_id' => $s, 'shop_name' => $shops[$s] ?? null, 'together' => $n,
                'confidence' => (int) round($n / max(1, min($A['orders'], $B['orders'])) * 100), 'lift' => $lift,
                'items' => [$A, $B], 'pair_price' => $A['price'] + $B['price'],
            ];
            if (count($out) >= self::LIMIT) {
                break;
            }
        }

        return ['pairs' => $out, 'summary' => ['orders' => $orders, 'multi_item_orders' => $multi, 'days' => $days]];
    }
}
