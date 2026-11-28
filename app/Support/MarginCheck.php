<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\ShopeeAdCampaign;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Cek Margin: profit per unit of every Shopee product variant at today's selling price.
 *   margin = selling price − Shopee fees − HPP − ad cost per unit sold
 * Selling price = average price actually paid in the last 30 days (discounts/promos included), else the list price.
 * Shopee fees = the shop's real fee rate (commission + service + transaction) over the last 60 days, else 10%.
 * Flags variants that lose money ("rugi") or earn less than the seller's minimum margin ("tipis").
 */
class MarginCheck
{
    public const SETTINGS_KEY = 'margin_alert';

    public const DEFAULT_FEE_RATE = 0.10;

    public static function threshold(User $user): int
    {
        return max(0, min(50, (int) ($user->settings[self::SETTINGS_KEY]['threshold'] ?? 10)));
    }

    /** @return array{rows: array<int, array>, summary: array} */
    public static function compute(User $user, ?int $shopId = null): array
    {
        $threshold = self::threshold($user);
        $shops = MarketplaceShop::where('user_id', $user->id)->where('platform', 'shopee')->where('status', '!=', 'disconnected')
            ->when($shopId, fn ($q) => $q->where('id', $shopId))->pluck('shop_name', 'id');
        $listings = MarketplaceListing::where('user_id', $user->id)->whereIn('marketplace_shop_id', $shops->keys())
            ->where('status', 'NORMAL')->where('price', '>', 0)->with('product:id,cost_price')
            ->get(['id', 'marketplace_shop_id', 'product_id', 'item_id', 'model_id', 'name', 'model_name', 'sku', 'price', 'cost_price', 'image']);

        // Real Shopee fee rate per shop.
        $feeRates = Order::where('user_id', $user->id)->whereIn('marketplace_shop_id', $shops->keys())->where('channel', 'shopee')
            ->whereNotNull('fee_breakdown')->where('subtotal', '>', 0)->where('created_at', '>=', now()->subDays(60))
            ->where(fn ($w) => $w->whereNull('external_status')->orWhereNotIn('external_status', ['CANCELLED', 'IN_CANCEL']))
            ->selectRaw('marketplace_shop_id, SUM(marketplace_fee) as fee, SUM(subtotal) as sub, COUNT(*) as n')->groupBy('marketplace_shop_id')->get()
            ->mapWithKeys(fn ($r) => [$r->marketplace_shop_id => $r->n >= 5 && $r->sub > 0 ? min(0.5, max(0, $r->fee / $r->sub)) : null]);

        // Units sold and average paid price per variant (last 30 days).
        $sold = self::sold($user, $shops->keys()->all(), 30);

        // Ad spend per product (last 30 days), shared equally between the products of a campaign.
        $adSpend = [];
        foreach (ShopeeAdCampaign::whereIn('marketplace_shop_id', $shops->keys())
            ->withSum(['stats as expense' => fn ($q) => $q->where('date', '>=', now('Asia/Jakarta')->subDays(29)->toDateString())], 'expense')->get() as $c) {
            $ids = array_values(array_filter(array_map('intval', $c->item_ids ?? [])));
            foreach ($ids as $iid) {
                $adSpend["{$c->marketplace_shop_id}:{$iid}"] = ($adSpend["{$c->marketplace_shop_id}:{$iid}"] ?? 0) + (float) $c->expense / count($ids);
            }
        }

        $rows = [];
        $noHpp = 0;
        foreach ($listings as $l) {
            $hpp = (int) ($l->cost_price ?: $l->product?->cost_price ?: 0);
            if ($hpp <= 0) {
                $noHpp++;

                continue;
            }
            $vk = "{$l->marketplace_shop_id}:{$l->item_id}:".(int) $l->model_id;
            $ik = "{$l->marketplace_shop_id}:{$l->item_id}";
            $v = $sold[$vk] ?? null;
            $price = $v && $v['units'] > 0 && $v['revenue'] > 0 ? (int) round($v['revenue'] / $v['units']) : (int) $l->price;
            $rate = $feeRates[$l->marketplace_shop_id] ?? null;
            $fee = (int) round($price * ($rate ?? self::DEFAULT_FEE_RATE));
            $itemUnits = $sold[$ik]['units'] ?? 0;
            $spend = $adSpend[$ik] ?? 0;
            $adPerUnit = $itemUnits > 0 ? (int) round($spend / $itemUnits) : 0;
            $margin = $price - $fee - $hpp - $adPerUnit;
            $pct = round($margin / $price * 100, 1);
            $minPrice = (int) (ceil(($hpp + $adPerUnit) / max(0.05, 1 - ($rate ?? self::DEFAULT_FEE_RATE) - $threshold / 100) / 100) * 100);
            // List price to set so buyers still pay at least the minimum price: buyers paid $price for a $l->price listing
            // on average (discounts), so keep that ratio. Null when the list price is already high enough (the promo is the problem).
            $ratio = $l->price > 0 ? min(1, $price / $l->price) : 1;
            $suggested = (int) (ceil($minPrice / max(0.3, $ratio) / 100) * 100);
            $rows[] = [
                'id' => $l->id, 'shop_id' => (int) $l->marketplace_shop_id, 'shop_name' => $shops[$l->marketplace_shop_id] ?? null,
                'item_id' => (int) $l->item_id, 'name' => $l->name, 'variant' => $l->model_name, 'sku' => $l->sku, 'image' => $l->image,
                'list_price' => (int) $l->price, 'price' => $price, 'price_source' => $v && $v['units'] > 0 ? 'sold' : 'list',
                'fee' => $fee, 'fee_rate' => round(($rate ?? self::DEFAULT_FEE_RATE) * 100, 1), 'fee_estimated' => $rate === null,
                'hpp' => $hpp, 'ad_per_unit' => $adPerUnit, 'ad_no_sales' => $spend > 0 && $itemUnits === 0, 'units_30d' => $v['units'] ?? 0,
                'margin' => $margin, 'margin_pct' => $pct,
                'status' => $margin < 0 ? 'rugi' : ($pct < $threshold ? 'tipis' : 'aman'),
                // Lowest price that still leaves the minimum margin: (hpp + ads) / (1 − fee rate − threshold).
                'min_price' => $minPrice,
                'suggested_price' => $margin < 0 || $pct < $threshold ? ($suggested > (int) $l->price ? $suggested : null) : null,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['status'] === 'aman', $a['margin_pct']] <=> [$b['status'] === 'aman', $b['margin_pct']]);
        $c = collect($rows);

        return ['rows' => $rows, 'summary' => [
            'threshold' => $threshold, 'checked' => count($rows), 'no_hpp' => $noHpp,
            'rugi' => $c->where('status', 'rugi')->count(), 'tipis' => $c->where('status', 'tipis')->count(), 'aman' => $c->where('status', 'aman')->count(),
        ]];
    }

    /**
     * Units sold and revenue per variant ("shop:item:model") and per item ("shop:item") over the last $days days,
     * from Shopee order lines (cancelled orders excluded). @return array<string, array{units: int, revenue: int}>
     */
    public static function sold(User $user, array $shopIds, int $days): array
    {
        $sold = [];
        $orders = Order::where('user_id', $user->id)->whereIn('marketplace_shop_id', $shopIds)->where('channel', 'shopee')
            ->where('created_at', '>=', now()->subDays($days))->whereNotIn('status', ['cancelled', 'refunded'])
            ->where(fn ($w) => $w->whereNull('external_status')->orWhereNotIn('external_status', ['CANCELLED', 'IN_CANCEL']))
            ->get(['marketplace_shop_id', 'items']);
        foreach ($orders as $o) {
            foreach ($o->items ?? [] as $it) {
                $iid = (int) ($it['item_id'] ?? 0);
                if (! $iid) {
                    continue;
                }
                $qty = max(1, (int) ($it['qty'] ?? 1));
                $price = (int) ($it['discounted_price'] ?? $it['price'] ?? 0);
                foreach (["{$o->marketplace_shop_id}:{$iid}:".(int) ($it['model_id'] ?? 0), "{$o->marketplace_shop_id}:{$iid}"] as $k) {
                    $sold[$k] ??= ['units' => 0, 'revenue' => 0];
                    $sold[$k]['units'] += $qty;
                    $sold[$k]['revenue'] += $price > 0 ? $price * $qty : 0;
                }
            }
        }

        return $sold;
    }

    /** Daily: notify when products newly turn "rugi" or "tipis" (not the same list every day). */
    public static function notify(User $user): int
    {
        $r = self::compute($user);
        $bad = collect($r['rows'])->whereIn('status', ['rugi', 'tipis']);
        $key = "margin_alert:{$user->id}";
        $before = Cache::get($key, []);
        $now = $bad->map(fn ($x) => $x['id'].':'.$x['status'])->values()->all();
        Cache::put($key, $now, now()->addDays(14));
        $new = $bad->reject(fn ($x) => in_array($x['id'].':'.$x['status'], $before, true));
        if ($new->isEmpty()) {
            return 0;
        }
        $lines = $new->take(6)->map(fn ($x) => '• '.($x['status'] === 'rugi' ? 'RUGI ' : '').mb_strimwidth($x['name'], 0, 40, '…').($x['variant'] ? " ({$x['variant']})" : '')
            .': margin '.number_format($x['margin'], 0, ',', '.').' ('.$x['margin_pct'].'%)')->implode("\n");
        $rugi = $new->where('status', 'rugi')->count();
        Notifier::send($user->id, 'margin', ($rugi ? "{$rugi} produk dijual rugi" : $new->count().' produk margin tipis').' di Shopee', $lines, '/marketplace/margins');

        return $new->count();
    }
}
