<?php

namespace App\Support;

use App\Models\AdSpend;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Omset, potongan marketplace, HPP, biaya iklan and laba bersih across every sales channel. */
class Analytics
{
    public const AD_PLATFORMS = ['meta' => 'Meta Ads (FB/IG)', 'tiktok' => 'TikTok Ads', 'google' => 'Google Ads', 'shopee_ads' => 'Shopee Ads', 'other' => 'Lainnya'];

    /** Orders that count as a sale: not abandoned/cancelled/refunded, and paid or COD. */
    public static function salesQuery(User $user, Carbon $from, Carbon $to, ?string $channel = null, ?int $shopId = null)
    {
        return $user->orders()->getQuery()
            ->where('is_abandoned', false)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where(fn ($q) => $q->where('payment_status', 'paid')->orWhere('payment_method', 'cod'))
            ->whereBetween('created_at', [$from, $to])
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId));
    }

    /** Per-order money breakdown. fee_final is false while Shopee has not released escrow. */
    public static function breakdown(Order $o, array $costs, array $shopFeeRate): array
    {
        if ($o->channel === 'shopee') {
            $revenue = (int) $o->subtotal;
            if ($o->escrow_amount !== null) {
                $fees = max(0, $revenue - (int) $o->escrow_amount);
                $final = true;
            } else {
                $fees = (int) round($revenue * ($shopFeeRate[$o->marketplace_shop_id] ?? 0));
                $final = false;
            }
            $cogs = collect($o->items ?? [])->sum(fn ($i) => (self::itemCost($o, $i, $costs) ?? 0) * ($i['qty'] ?? 1));
            $missingCost = collect($o->items ?? [])->contains(fn ($i) => self::itemCost($o, $i, $costs) === null);
        } else {
            $revenue = max(0, (int) $o->total - (int) $o->shipping_cost - (int) $o->cod_fee);
            $fees = 0;
            $final = true;
            $cogs = ($costs[$o->product_id] ?? 0) * $o->quantity;
            $missingCost = ! isset($costs[$o->product_id]);
        }

        return ['gmv' => (int) $o->total, 'revenue' => $revenue, 'fees' => $fees, 'cogs' => $cogs, 'fee_final' => $final, 'missing_cost' => $missingCost];
    }

    /** @return array{0: Collection, 1: array} sale rows with money breakdown, and cost price per product */
    private static function rows(User $user, Carbon $from, Carbon $to, ?string $channel = null, ?int $shopId = null): array
    {
        $orders = self::salesQuery($user, $from, $to, $channel, $shopId)->with('product:id,name')->get();
        $costs = Product::withTrashed()->where('user_id', $user->id)->whereNotNull('cost_price')->where('cost_price', '>', 0)->pluck('cost_price', 'id')->map(fn ($v) => (int) $v)->all();
        $feeRate = self::shopFeeRates($user);
        self::$listingCosts = MarketplaceListing::where('user_id', $user->id)->where('cost_price', '>', 0)->get()
            ->mapWithKeys(fn ($l) => [$l->marketplace_shop_id.':'.$l->item_id.':'.$l->model_id => (int) $l->cost_price])->all();

        return [$orders->map(fn (Order $o) => ['order' => $o] + self::breakdown($o, $costs, $feeRate)), $costs];
    }

    /** Sales per product keyed "p{id}" (linked) or "n{name}" (Shopee item not linked to a Pesanin product yet). */
    private static function productsAgg(Collection $rows, array $costs): array
    {
        $products = [];
        foreach ($rows as $r) {
            $o = $r['order'];
            $lines = $o->channel === 'shopee'
                ? collect($o->items ?? [])->map(fn ($i) => [($i['product_id'] ?? null) ? 'p'.$i['product_id'] : 'n'.$i['name'], $i['name'], $i['qty'], $i['qty'] * $i['price'], (self::itemCost($o, $i, $costs) ?? 0) * $i['qty']])
                : collect([['p'.$o->product_id, $o->product?->name ?? 'Produk dihapus', $o->quantity, $r['revenue'], $r['cogs']]]);
            $lineTotal = max(1, $lines->sum(fn ($l) => $l[3]));
            foreach ($lines as [$key, $name, $qty, $rev, $cogs]) {
                $p = $products[$key] ?? ['name' => $name, 'qty' => 0, 'revenue' => 0, 'fees' => 0, 'cogs' => 0, 'channels' => []];
                $p['qty'] += $qty;
                $p['revenue'] += $rev;
                $p['cogs'] += $cogs;
                $p['fees'] += (int) round($r['fees'] * $rev / $lineTotal);
                $p['channels'][$o->channel] = ($p['channels'][$o->channel] ?? 0) + $qty;
                $products[$key] = $p;
            }
        }

        return array_map(fn ($p) => $p + ['gross' => $p['revenue'] - $p['fees'] - $p['cogs']], $products);
    }

    public static function profit(User $user, Carbon $from, Carbon $to, ?string $channel = null, ?int $shopId = null): array
    {
        [$rows, $costs] = self::rows($user, $from, $to, $channel, $shopId);
        $shops = MarketplaceShop::where('user_id', $user->id)->get()->keyBy('id');

        // Shopee Ads belong to the Shopee channel (per shop); Meta/TikTok/Google drive website & landing traffic.
        $ads = AdSpend::where('user_id', $user->id)->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->when($channel === 'shopee' || $shopId, fn ($q) => $q->where('platform', 'shopee_ads'))
            ->when($channel && $channel !== 'shopee', fn ($q) => $q->where('platform', '!=', 'shopee_ads'))
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->get();

        $label = fn (string $k) => match (true) {
            str_starts_with($k, 'shopee:') => 'Shopee · '.($shops[(int) substr($k, 7)]->shop_name ?? 'Toko'),
            str_starts_with($k, 'website:') => 'Checkout Web · '.Traffic::label(substr($k, 8)),
            $k === 'manual' => 'Order manual',
            default => 'Checkout Web',
        };
        $orderKey = fn (Order $o) => match ($o->channel) {
            'shopee' => 'shopee:'.$o->marketplace_shop_id,
            'website' => 'website:'.Traffic::of($o->utm_source),
            default => $o->channel,
        };

        $byChannel = [];
        foreach ($rows->groupBy(fn ($r) => $orderKey($r['order'])) as $k => $g) {
            $byChannel[$k] = ['key' => $k, 'label' => $label($k), 'ads' => 0] + self::sum($g);
        }
        foreach ($ads->groupBy(fn ($a) => $a->platform === 'shopee_ads' ? 'shopee:'.$a->marketplace_shop_id : 'website:'.(Traffic::AD_PLATFORM[$a->platform] ?? 'other')) as $k => $g) {
            $byChannel[$k] ??= ['key' => $k, 'label' => $label($k), 'ads' => 0] + self::sum(collect());
            $byChannel[$k]['ads'] = (int) $g->sum('amount');
        }
        $byChannel = collect($byChannel)->map(fn ($c) => self::finish($c))->sortByDesc('revenue')->values();

        $daily = [];
        for ($d = $from->copy()->startOfDay(); $d <= $to; $d->addDay()) {
            $daily[$d->toDateString()] = ['date' => $d->toDateString(), 'revenue' => 0, 'net' => 0, 'ads' => 0];
        }
        foreach ($rows as $r) {
            $k = $r['order']->created_at->toDateString();
            if (isset($daily[$k])) {
                $daily[$k]['revenue'] += $r['revenue'];
                $daily[$k]['net'] += $r['revenue'] - $r['fees'] - $r['cogs'];
            }
        }
        foreach ($ads as $a) {
            $k = $a->spent_on->toDateString();
            if (isset($daily[$k])) {
                $daily[$k]['ads'] += $a->amount;
                $daily[$k]['net'] -= $a->amount;
            }
        }

        $products = collect(self::productsAgg($rows, $costs))->map(fn ($p, $k) => $p + ['key' => $k])->sortByDesc('revenue')->values()->take(20);

        $summary = self::finish(self::sum($rows) + ['ads' => (int) $ads->sum('amount')]);
        $summary['pending_fee_orders'] = $rows->where('fee_final', false)->count();
        $summary['missing_cost_orders'] = $rows->where('missing_cost', true)->count();

        return [
            'summary' => $summary,
            'by_channel' => $byChannel,
            'daily' => array_values($daily),
            'products' => $products,
            'ads_by_platform' => $ads->groupBy('platform')->map(fn ($g, $p) => ['platform' => $p, 'label' => self::AD_PLATFORMS[$p] ?? $p, 'amount' => (int) $g->sum('amount')])->values(),
        ];
    }

    /** Financial ledger rows for a Shopee shop, separate from fulfilment actions. */
    public static function transactions(User $user, Carbon $from, Carbon $to, int $shopId): Collection
    {
        $orders = $user->orders()->where('channel', 'shopee')->where('marketplace_shop_id', $shopId)
            ->where('is_abandoned', false)->whereBetween('created_at', [$from, $to])->latest()->get();
        $costs = Product::withTrashed()->where('user_id', $user->id)->where('cost_price', '>', 0)
            ->pluck('cost_price', 'id')->map(fn ($v) => (int) $v)->all();
        self::$listingCosts = MarketplaceListing::where('user_id', $user->id)->where('marketplace_shop_id', $shopId)->where('cost_price', '>', 0)->get()
            ->mapWithKeys(fn ($l) => [$l->marketplace_shop_id.':'.$l->item_id.':'.$l->model_id => (int) $l->cost_price])->all();
        $feeRates = self::shopFeeRates($user);

        return $orders->map(function (Order $order) use ($costs, $feeRates) {
            $money = self::breakdown($order, $costs, $feeRates);
            $netRevenue = $money['revenue'] - $money['fees'];
            $profit = $netRevenue - $money['cogs'];

            return [
                'id' => $order->id, 'order_sn' => $order->external_order_sn, 'created_at' => $order->created_at,
                'status' => $order->external_status, 'customer_name' => $order->customer_name,
                'tracking_number' => $order->tracking_number, 'courier' => $order->courier, 'items' => $order->items ?? [],
                'quantity' => $order->quantity, 'gmv' => $money['gmv'], 'revenue' => $money['revenue'],
                'fees' => $money['fees'], 'net_revenue' => $netRevenue, 'cogs' => $money['cogs'],
                'profit' => $profit, 'margin' => $money['revenue'] ? round($profit / $money['revenue'] * 100, 1) : 0,
                'fee_final' => $money['fee_final'], 'missing_cost' => $money['missing_cost'],
            ];
        });
    }

    /** Last 30 days vs the 30 before, per product across all channels, with stock cover. */
    public static function research(User $user): array
    {
        $now = now()->endOfDay();
        $cur = self::productsAgg(...self::rows($user, now()->subDays(29)->startOfDay(), $now));
        $prev = self::productsAgg(...self::rows($user, now()->subDays(59)->startOfDay(), now()->subDays(30)->endOfDay()));
        $stock = Product::where('user_id', $user->id)->get(['id', 'name', 'stock', 'track_stock', 'cost_price', 'price', 'sale_price'])->keyBy('id');
        $listingStock = \App\Models\MarketplaceListing::where('user_id', $user->id)->whereNotNull('product_id')->selectRaw('product_id, SUM(stock) s, COUNT(*) n')->groupBy('product_id')->get()->keyBy('product_id');

        $keys = collect(array_keys($cur))->merge(array_keys($prev))->unique();

        return $keys->map(function ($k) use ($cur, $prev, $stock, $listingStock) {
            $c = $cur[$k] ?? ['name' => $prev[$k]['name'], 'qty' => 0, 'revenue' => 0, 'gross' => 0, 'channels' => []];
            $p = $prev[$k] ?? ['qty' => 0, 'revenue' => 0];
            $pid = str_starts_with($k, 'p') ? (int) substr($k, 1) : null;
            $prod = $pid ? $stock[$pid] ?? null : null;
            $available = null;
            if ($prod?->track_stock) {
                $available = (int) $prod->stock;
            } elseif ($pid && isset($listingStock[$pid])) {
                $available = (int) $listingStock[$pid]->s;
            }
            $daily = $c['qty'] / 30;
            $growth = $p['qty'] ? round(($c['qty'] - $p['qty']) / $p['qty'] * 100) : ($c['qty'] ? 100 : 0);
            $margin = $c['revenue'] ? round($c['gross'] / $c['revenue'] * 100) : null;
            $cover = $available !== null && $daily > 0 ? (int) floor($available / $daily) : null;

            $tags = [];
            if ($c['qty'] >= 10 && $growth >= 20) $tags[] = 'naik';
            if ($p['qty'] >= 5 && $growth <= -30) $tags[] = 'turun';
            if ($cover !== null && $cover <= 7) $tags[] = 'stok_menipis';
            if ($margin !== null && $margin < 15) $tags[] = 'margin_tipis';
            if ($prod && ! $prod->cost_price) $tags[] = 'hpp_kosong';
            if (! $pid && $c['revenue'] && $c['cogs'] === 0) $tags[] = 'hpp_kosong';

            return [
                'key' => $k, 'product_id' => $pid, 'name' => $c['name'], 'qty' => $c['qty'], 'qty_prev' => $p['qty'], 'growth' => $growth,
                'revenue' => $c['revenue'], 'gross' => $c['gross'], 'margin' => $margin, 'channels' => $c['channels'],
                'stock' => $available, 'daily' => round($daily, 1), 'cover_days' => $cover, 'tags' => $tags,
            ];
        })->sortByDesc('revenue')->values()->all();
    }

    /** @var array<string, int> HPP per Shopee listing, keyed "shop:item:model" */
    private static array $listingCosts = [];

    /** HPP of one Shopee line: the listing's own HPP first, else the linked Pesanin product's. Null when unknown. */
    private static function itemCost(Order $o, array $i, array $costs): ?int
    {
        return self::$listingCosts[$o->marketplace_shop_id.':'.$i['item_id'].':'.($i['model_id'] ?? 0)]
            ?? self::$listingCosts[$o->marketplace_shop_id.':'.$i['item_id'].':0']
            ?? $costs[$i['product_id'] ?? 0] ?? null;
    }

    /** Shopee fee ratio per shop, learned from orders whose escrow is already final. */
    public static function shopFeeRates(User $user): array
    {
        return Order::where('user_id', $user->id)->where('channel', 'shopee')->whereNotNull('escrow_amount')->where('subtotal', '>', 0)
            ->selectRaw('marketplace_shop_id, SUM(subtotal - escrow_amount) / SUM(subtotal) r')->groupBy('marketplace_shop_id')
            ->pluck('r', 'marketplace_shop_id')->map(fn ($r) => max(0, (float) $r))->all();
    }

    private static function sum(Collection $rows): array
    {
        return [
            'orders' => $rows->count(),
            'items' => (int) $rows->sum(fn ($r) => $r['order']->quantity),
            'gmv' => (int) $rows->sum('gmv'), 'revenue' => (int) $rows->sum('revenue'),
            'fees' => (int) $rows->sum('fees'), 'cogs' => (int) $rows->sum('cogs'),
        ];
    }

    private static function finish(array $c): array
    {
        $c['gross_profit'] = $c['revenue'] - $c['fees'] - $c['cogs'];
        $c['net_profit'] = $c['gross_profit'] - $c['ads'];
        $c['margin'] = $c['revenue'] ? round($c['net_profit'] / $c['revenue'] * 100, 1) : 0;
        $c['roas'] = $c['ads'] ? round($c['revenue'] / $c['ads'], 2) : null;

        return $c;
    }
}
