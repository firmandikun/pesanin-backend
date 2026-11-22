<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceShop;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeeProductTools;
use Illuminate\Http\Request;

/** Marketplace → Semua Produk: Naikkan Produk otomatis, statistik produk, ubah harga / judul / arsip massal. */
class ShopeeProductToolsController extends Controller
{
    private function shop(Request $request, int $id): MarketplaceShop
    {
        $shop = MarketplaceShop::where('user_id', $request->user()->id)->findOrFail($id);
        abort_if($shop->is_demo, 422, 'Toko contoh tidak terhubung ke Shopee.');

        return $shop;
    }

    private function shopee(callable $fn)
    {
        try {
            return $fn();
        } catch (ShopeeException $e) {
            abort(422, 'Shopee menolak: '.$e->getMessage());
        }
    }

    /** Boost setup of every shop. */
    public function boostIndex(Request $request)
    {
        return ['slots' => ShopeeProductTools::BOOST_SLOTS, 'shops' => MarketplaceShop::where('user_id', $request->user()->id)->where('is_demo', false)
            ->where('status', '!=', 'disconnected')->get(['id', 'shop_name', 'boost'])
            ->map(function ($s) {
                $cfg = ShopeeProductTools::boostConfig($s);
                $names = \App\Models\MarketplaceListing::where('marketplace_shop_id', $s->id)->whereIn('item_id', $cfg['items'] ?: [0])
                    ->get(['item_id', 'name', 'image', 'status'])->unique('item_id')->keyBy('item_id');

                return ['id' => $s->id, 'shop_name' => $s->shop_name] + $cfg + ['products' => collect($cfg['items'])->map(fn ($i) => [
                    'item_id' => (int) $i, 'name' => $names[$i]->name ?? "Produk {$i}", 'image' => $names[$i]->image ?? null, 'status' => $names[$i]->status ?? null,
                ])->values()];
            })->values()];
    }

    public function boostUpdate(Request $request, int $shop)
    {
        $s = $this->shop($request, $shop);
        $d = $request->validate(['enabled' => 'sometimes|boolean', 'items' => 'sometimes|array|max:500', 'items.*' => 'integer']);
        $cfg = ShopeeProductTools::boostConfig($s);
        if (array_key_exists('items', $d)) {
            $cfg['items'] = array_values(array_unique(array_map('intval', $d['items'])));
            $cfg['cursor'] = 0;
        }
        if (array_key_exists('enabled', $d)) {
            $cfg['enabled'] = $d['enabled'];
        }
        $s->update(['boost' => $cfg]);
        // Turning it on boosts right away instead of waiting for the next run.
        if ($cfg['enabled'] && $cfg['items']) {
            $cfg = (new ShopeeProductTools)->boost($s->fresh());
        }

        return ['id' => $s->id, 'shop_name' => $s->shop_name] + $cfg;
    }

    public function boostRun(Request $request, int $shop)
    {
        $s = $this->shop($request, $shop);

        return ['id' => $s->id, 'shop_name' => $s->shop_name] + (new ShopeeProductTools)->boost($s, true);
    }

    /** Refresh views / likes / sales of every product now (also runs daily). */
    public function statsSync(Request $request)
    {
        $n = 0;
        $errors = [];
        foreach (MarketplaceShop::where('user_id', $request->user()->id)->where('is_demo', false)->where('status', 'active')->get() as $s) {
            try {
                $n += (new ShopeeProductTools)->syncStats($s);
            } catch (\Throwable $e) {
                $errors[] = "{$s->shop_name}: {$e->getMessage()}";
            }
        }

        return ['products' => $n, 'errors' => $errors];
    }

    public function prices(Request $request)
    {
        $d = $request->validate(['prices' => 'required|array|min:1|max:500', 'prices.*.id' => 'required|integer', 'prices.*.price' => 'required|integer|min:100|max:1000000000']);

        return (new ShopeeProductTools)->updatePrices($request->user()->id, collect($d['prices'])->mapWithKeys(fn ($p) => [(int) $p['id'] => (int) $p['price']])->all());
    }

    public function unlist(Request $request)
    {
        $d = $request->validate(['items' => 'required|array|min:1|max:500', 'items.*.shop_id' => 'required|integer', 'items.*.item_id' => 'required|integer', 'unlist' => 'required|boolean']);

        return (new ShopeeProductTools)->setUnlisted($request->user()->id, collect($d['items'])->map(fn ($i) => [(int) $i['shop_id'], (int) $i['item_id']])->all(), $d['unlist']);
    }

    /** Buat SKU: suggestions for products without SKU (or the selected ones), editable before saving. */
    public function skuPreview(Request $request)
    {
        $d = $request->validate(['mode' => 'required|in:abbr,number', 'prefix' => 'nullable|string|max:12', 'start' => 'nullable|integer|min:1',
            'scope' => 'required|in:empty,selected', 'keys' => 'array|max:500', 'keys.*' => 'string', 'shop_id' => 'nullable|integer']);
        $q = \App\Models\MarketplaceListing::where('user_id', $request->user()->id)->with('shop:id,shop_name,is_demo')
            ->whereHas('shop', fn ($s) => $s->where('is_demo', false)->where('status', '!=', 'disconnected'))
            ->where('status', 'NORMAL')
            ->when($d['shop_id'] ?? null, fn ($w, $id) => $w->where('marketplace_shop_id', $id));
        if ($d['scope'] === 'selected') {
            $keys = collect($d['keys'] ?? [])->map(fn ($k) => array_map('intval', explode(':', $k)));
            abort_if($keys->isEmpty(), 422, 'Pilih produk dulu.');
            $q->where(fn ($w) => $keys->each(fn ($k) => $w->orWhere(fn ($x) => $x->where('marketplace_shop_id', $k[0])->where('item_id', $k[1] ?? 0))));
        } else {
            $q->where(fn ($w) => $w->whereNull('sku')->orWhere('sku', ''));
        }
        $listings = $q->limit(500)->get();

        return ['rows' => \App\Support\Shopee\SkuGenerator::suggest($request->user()->id, $listings, $d['mode'], (string) ($d['prefix'] ?? ''), (int) ($d['start'] ?? 1))];
    }

    public function skuSave(Request $request)
    {
        $d = $request->validate(['rows' => 'required|array|min:1|max:500', 'rows.*.id' => 'required|integer', 'rows.*.sku' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/']],
            ['rows.*.sku.regex' => 'SKU hanya boleh huruf, angka, titik, garis miring, strip dan garis bawah (tanpa spasi).']);
        $stocks = $request->validate(['stocks' => 'nullable|array|max:500', 'stocks.*' => 'integer|min:0|max:1000000'])['stocks'] ?? [];
        $skus = collect($d['rows'])->mapWithKeys(fn ($r) => [(int) $r['id'] => strtoupper(trim($r['sku']))])->all();
        $res = (new \App\Support\Shopee\SkuGenerator)->save($request->user()->id, $skus);
        // Shared stock for SKUs used by several listings (only those whose SKU was saved).
        $ok = collect($skus)->except(array_keys($res['failed']))->values()->map(fn ($x) => strtoupper($x))->unique()->all();
        $stocks = collect($stocks)->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => (int) $v])->only($ok)->all();
        $res['masters'] = 0;
        if ($stocks) {
            if (! \App\Support\Plans::hasFeature($request->user(), 'stock_master')) {
                $res['stock_note'] = 'Stok bersama butuh fitur Stok Master (upgrade paket). SKU tetap tersimpan.';
            } else {
                $res['masters'] = \App\Support\Shopee\SkuGenerator::shareStock($request->user(), $stocks);
            }
        }

        return $res;
    }

    public function itemText(Request $request, int $shop, int $item)
    {
        $s = $this->shop($request, $shop);

        return $this->shopee(fn () => (new ShopeeProductTools)->itemText($s, $item));
    }

    public function updateText(Request $request, int $shop, int $item)
    {
        $s = $this->shop($request, $shop);
        $d = $request->validate(['name' => 'required|string|min:10|max:255', 'description' => 'nullable|string|min:20|max:5000']);
        $this->shopee(fn () => (new ShopeeProductTools)->updateText($s, $item, trim($d['name']), isset($d['description']) ? trim($d['description']) : null));

        return ['ok' => true, 'name' => trim($d['name'])];
    }

    /** Product performance report combining Shopee get_item_extra_info snapshots with period order sales. */
    /** Cek Margin: profit per unit of every variant (price − Shopee fees − HPP − ads), losing / thin ones first. */
    public function margins(Request $request)
    {
        return \App\Support\MarginCheck::compute($request->user(), $request->integer('shop_id') ?: null);
    }

    public function performance(Request $request)
    {
        $userId = $request->user()->id;
        $shopId = $request->integer('shop_id') ?: null;
        $from = $request->string('from')->toString() ?: now('Asia/Jakarta')->subDays(29)->toDateString();
        $to = $request->string('to')->toString() ?: now('Asia/Jakarta')->toDateString();
        $search = mb_strtolower(trim($request->string('q')->toString()));
        $sort = $request->string('sort')->toString() ?: 'revenue';
        $adFilter = $request->string('ad_filter')->toString() ?: 'all';

        $shops = MarketplaceShop::where('user_id', $userId)
            ->where('status', '!=', 'disconnected')
            ->get(['id', 'shop_name', 'is_demo', 'status']);

        $listings = \App\Models\MarketplaceListing::where('user_id', $userId)
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->whereIn('marketplace_shop_id', $shops->pluck('id'))
            ->where('status', 'NORMAL')
            ->with(['stockMaster:id,stock', 'product:id,cost_price'])
            ->get(['id', 'marketplace_shop_id', 'product_id', 'item_id', 'model_id', 'model_name', 'stock_master_id', 'name', 'sku', 'image', 'price', 'cost_price', 'stock', 'status']);

        $grouped = $listings->groupBy(fn ($l) => "{$l->marketplace_shop_id}:{$l->item_id}");
        $keys = $grouped->map(fn ($g) => [(int) $g->first()->marketplace_shop_id, (int) $g->first()->item_id])->values();
        $stats = ShopeeProductTools::statsFor($keys);

        $adCampaigns = \App\Models\ShopeeAdCampaign::whereIn('marketplace_shop_id', $shops->pluck('id'))
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->withSum(['stats as expense' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'expense')
            ->withSum(['stats as gmv' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'gmv')
            ->withSum(['stats as clicks' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'clicks')
            ->withSum(['stats as impressions' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'impressions')
            ->withSum(['stats as orders' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'orders')
            ->get();

        $adByItem = [];
        $shopAutoAds = [];
        foreach ($adCampaigns as $c) {
            $itemIds = array_values(array_filter(array_map('intval', $c->item_ids ?? [])));
            if (! count($itemIds)) {
                if (($c->ad_type === 'auto' || $c->ad_type === 'shop_auto') && ($c->status ?? 'ongoing') === 'ongoing') {
                    $shopAutoAds[$c->marketplace_shop_id] = true;
                }
                continue;
            }
            $share = 1 / count($itemIds);
            foreach ($itemIds as $iid) {
                $k = "{$c->marketplace_shop_id}:{$iid}";
                $adByItem[$k] ??= ['campaigns' => 0, 'active' => false, 'expense' => 0.0, 'gmv' => 0.0, 'clicks' => 0.0, 'impressions' => 0.0, 'orders' => 0.0];
                $adByItem[$k]['campaigns']++;
                if (($c->status ?? 'ongoing') === 'ongoing') {
                    $adByItem[$k]['active'] = true;
                }
                $adByItem[$k]['expense'] += ((float) $c->expense) * $share;
                $adByItem[$k]['gmv'] += ((float) $c->gmv) * $share;
                $adByItem[$k]['clicks'] += ((float) $c->clicks) * $share;
                $adByItem[$k]['impressions'] += ((float) $c->impressions) * $share;
                $adByItem[$k]['orders'] += ((float) $c->orders) * $share;
            }
        }

        $fromUtc = \Carbon\Carbon::parse($from, 'Asia/Jakarta')->startOfDay()->utc();
        $toUtc = \Carbon\Carbon::parse($to, 'Asia/Jakarta')->endOfDay()->utc();
        $orders = \App\Models\Order::where('user_id', $userId)
            ->where('channel', 'shopee')
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where(fn ($w) => $w->whereNull('external_status')->orWhereNotIn('external_status', ['CANCELLED', 'IN_CANCEL']))
            ->get(['id', 'marketplace_shop_id', 'items']);

        $periodSales = [];
        $variantSales = [];
        foreach ($orders as $o) {
            $seenInOrder = [];
            $seenVarInOrder = [];
            foreach ($o->items ?? [] as $it) {
                $iid = (int) ($it['item_id'] ?? 0);
                if (! $iid) {
                    continue;
                }
                $key = "{$o->marketplace_shop_id}:{$iid}";
                $qty = max(1, (int) ($it['qty'] ?? 1));
                $unitPrice = (int) ($it['discounted_price'] ?? $it['price'] ?? 0);
                $rev = (int) ($it['subtotal'] ?? ($unitPrice * $qty));
                $periodSales[$key] ??= ['units' => 0, 'orders' => 0, 'revenue' => 0];
                $periodSales[$key]['units'] += $qty;
                $periodSales[$key]['revenue'] += $rev;
                if (! isset($seenInOrder[$key])) {
                    $periodSales[$key]['orders']++;
                    $seenInOrder[$key] = true;
                }

                $mid = (int) ($it['model_id'] ?? 0);
                $vName = mb_strtolower(trim((string) ($it['variant'] ?? '')));
                $vKey = $mid > 0 ? "{$key}:m:{$mid}" : ($vName !== '' ? "{$key}:n:{$vName}" : "{$key}:m:0");
                $variantSales[$vKey] ??= ['units' => 0, 'orders' => 0, 'revenue' => 0];
                $variantSales[$vKey]['units'] += $qty;
                $variantSales[$vKey]['revenue'] += $rev;
                if (! isset($seenVarInOrder[$vKey])) {
                    $variantSales[$vKey]['orders']++;
                    $seenVarInOrder[$vKey] = true;
                }
            }
        }

        $shopNames = $shops->pluck('shop_name', 'id');
        $rows = [];
        foreach ($grouped as $key => $variants) {
            $first = $variants->first();
            $skus = $variants->pluck('sku')->filter()->unique()->values()->all();
            if ($search !== '' && ! str_contains(mb_strtolower($first->name), $search) && ! str_contains(mb_strtolower(implode(' ', $skus)), $search)) {
                continue;
            }
            $st = $stats[$key] ?? null;
            $ps = $periodSales[$key] ?? ['units' => 0, 'orders' => 0, 'revenue' => 0];
            $ad = $adByItem[$key] ?? null;
            $hasAutoAd = ! empty($shopAutoAds[$first->marketplace_shop_id]);
            $adActive = ($ad && $ad['active']) || $hasAutoAd;
            $isAdvertised = $ad !== null || $hasAutoAd;
            if ($adFilter === 'advertised' && ! $adActive) {
                continue;
            }
            if ($adFilter === 'organic' && $adActive) {
                continue;
            }

            // Use Stok Master when linked (deduplicated per stock_master_id so shared SKU isn't double-counted)
            $seenMasters = [];
            $masterStock = 0;
            $hasMasterStock = false;
            $shopeeStock = (int) $variants->sum('stock');
            foreach ($variants as $v) {
                if ($v->stock_master_id && $v->stockMaster) {
                    $hasMasterStock = true;
                    if (! isset($seenMasters[$v->stock_master_id])) {
                        $seenMasters[$v->stock_master_id] = true;
                        $masterStock += (int) $v->stockMaster->stock;
                    }
                } else {
                    $masterStock += (int) $v->stock;
                }
            }

            $adExpense = $ad ? (int) round($ad['expense']) : 0;
            $adGmv = $ad ? (int) round($ad['gmv']) : 0;
            $adRoas = $adExpense > 0 ? round($adGmv / $adExpense, 2) : 0;

            $variantRows = $variants->map(function ($v) use ($key, $variantSales) {
                $mid = (int) ($v->model_id ?? 0);
                $vName = mb_strtolower(trim((string) ($v->model_name ?? '')));
                $vs = ($mid > 0 ? ($variantSales["{$key}:m:{$mid}"] ?? null) : null)
                    ?? ($vName !== '' ? ($variantSales["{$key}:n:{$vName}"] ?? null) : null)
                    ?? ($variantSales["{$key}:m:0"] ?? ['units' => 0, 'orders' => 0, 'revenue' => 0]);

                $hpp = (int) ($v->cost_price ?: $v->product?->cost_price ?: 0);

                return [
                    'id' => $v->id,
                    'model_id' => $mid,
                    'name' => $v->model_name ?: ($v->sku ?: 'Standar'),
                    'sku' => $v->sku,
                    'price' => (int) $v->price,
                    'cost_price' => $hpp > 0 ? $hpp : null,
                    'stock' => ($v->stock_master_id && $v->stockMaster) ? (int) $v->stockMaster->stock : (int) $v->stock,
                    'shopee_stock' => (int) $v->stock,
                    'has_master_stock' => (bool) ($v->stock_master_id && $v->stockMaster),
                    'period_units' => $vs['units'],
                    'period_orders' => $vs['orders'],
                    'period_revenue' => $vs['revenue'],
                ];
            })->sortByDesc('period_units')->values()->all();

            $views = (int) ($st['views'] ?? 0);
            $sales = (int) ($st['sales'] ?? 0);
            $views7d = $st['views_7d'] ?? null;
            $sales7d = $st['sales_7d'] ?? null;
            $needsAttention = $views >= 100 && $ps['units'] === 0 && ($sales7d === null || $sales7d === 0);

            $rows[] = [
                'key' => $key,
                'shop_id' => (int) $first->marketplace_shop_id,
                'shop_name' => $shopNames[$first->marketplace_shop_id] ?? 'Toko',
                'item_id' => (int) $first->item_id,
                'name' => $first->name,
                'sku' => $skus[0] ?? null,
                'image' => $first->image,
                'price_min' => (int) $variants->min('price'),
                'price_max' => (int) $variants->max('price'),
                'hpp_min' => collect($variantRows)->pluck('cost_price')->filter()->min(),
                'hpp_max' => collect($variantRows)->pluck('cost_price')->filter()->max(),
                'stock' => $hasMasterStock ? $masterStock : $shopeeStock,
                'shopee_stock' => $shopeeStock,
                'has_master_stock' => $hasMasterStock,
                'status' => $first->status,
                'views' => $views,
                'likes' => (int) ($st['likes'] ?? 0),
                'sales' => $sales,
                'rating' => $st['rating'] ?? null,
                'comments' => (int) ($st['comments'] ?? 0),
                'views_7d' => $views7d,
                'sales_7d' => $sales7d,
                'stat_date' => $st['date'] ?? null,
                'period_units' => $ps['units'],
                'period_orders' => $ps['orders'],
                'period_revenue' => $ps['revenue'],
                'conversion_rate' => $views > 0 ? round(($sales / $views) * 100, 2) : 0,
                'needs_attention' => $needsAttention,
                'is_advertised' => $isAdvertised,
                'ad_active' => $adActive,
                'ad_auto' => $ad === null && $hasAutoAd,
                'ad_campaigns' => $ad ? $ad['campaigns'] : ($hasAutoAd ? 1 : 0),
                'ad_expense' => $adExpense,
                'ad_gmv' => $adGmv,
                'ad_clicks' => $ad ? (int) round($ad['clicks']) : 0,
                'ad_impressions' => $ad ? (int) round($ad['impressions']) : 0,
                'ad_orders' => $ad ? (int) round($ad['orders']) : 0,
                'ad_roas' => $adRoas,
                'variants' => $variantRows,
            ];
        }

        usort($rows, function ($a, $b) use ($sort) {
            switch ($sort) {
                case 'units':
                    return ($b['period_units'] <=> $a['period_units']) ?: ($b['period_revenue'] <=> $a['period_revenue']);
                case 'views':
                    return $b['views'] <=> $a['views'];
                case 'likes':
                    return $b['likes'] <=> $a['likes'];
                case 'sales':
                    return $b['sales'] <=> $a['sales'];
                case 'rating':
                    return (($b['rating'] ?? 0) <=> ($a['rating'] ?? 0)) ?: ($b['comments'] <=> $a['comments']);
                case 'attention':
                    return ($b['needs_attention'] <=> $a['needs_attention']) ?: ($b['views'] <=> $a['views']);
                case 'ad_expense':
                    return ($b['ad_expense'] <=> $a['ad_expense']) ?: ($b['ad_gmv'] <=> $a['ad_gmv']);
                case 'ad_roas':
                    return ($b['ad_roas'] <=> $a['ad_roas']) ?: ($b['ad_gmv'] <=> $a['ad_gmv']);
                default:
                    return ($b['period_revenue'] <=> $a['period_revenue']) ?: ($b['ad_expense'] <=> $a['ad_expense']) ?: ($b['views'] <=> $a['views']);
            }
        });

        $rated = array_filter($rows, fn ($r) => $r['rating'] !== null);
        $dates = array_filter(array_column($rows, 'stat_date'));
        $totalAdExpense = array_sum(array_column($rows, 'ad_expense'));
        $totalAdGmv = array_sum(array_column($rows, 'ad_gmv'));

        return [
            'from' => $from,
            'to' => $to,
            'shops' => $shops->values(),
            'summary' => [
                'total_products' => count($rows),
                'total_views' => array_sum(array_column($rows, 'views')),
                'total_likes' => array_sum(array_column($rows, 'likes')),
                'total_sales' => array_sum(array_column($rows, 'sales')),
                'period_units' => array_sum(array_column($rows, 'period_units')),
                'period_revenue' => array_sum(array_column($rows, 'period_revenue')),
                'avg_rating' => count($rated) ? round(array_sum(array_column($rated, 'rating')) / count($rated), 2) : null,
                'low_conversion_count' => count(array_filter($rows, fn ($r) => $r['needs_attention'])),
                'advertised_count' => count(array_filter($rows, fn ($r) => $r['ad_active'])),
                'total_ad_expense' => $totalAdExpense,
                'total_ad_gmv' => $totalAdGmv,
                'total_ad_roas' => $totalAdExpense > 0 ? round($totalAdGmv / $totalAdExpense, 2) : 0,
                'last_synced_date' => $dates ? max($dates) : null,
            ],
            'items' => $rows,
        ];
    }
}
