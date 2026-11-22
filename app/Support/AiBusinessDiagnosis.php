<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopeeAdCampaign;
use App\Models\ShopeeReturn;
use App\Models\StockMaster;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Support\Ai\Gemini;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gathers real profit, product margin, ad waste, returns, and warehouse stock data,
 * then asks the seller's Gemini API key for an actionable Indonesian business diagnosis.
 */
class AiBusinessDiagnosis
{
    public static function verifyKey(string $apiKey): void
    {
        $key = trim($apiKey);
        if ($key === '') {
            throw new RuntimeException('Gemini API key wajib diisi.');
        }
        $res = Http::timeout(12)->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $key]);
        if (! $res->successful()) {
            $msg = $res->json('error.message') ?? 'API key Gemini tidak valid atau ditolak Google.';
            throw new RuntimeException($msg);
        }
    }

    /** Build a complete financial & product performance snapshot for [$from, $to]. */
    public static function snapshot(User $user, Carbon $from, Carbon $to): array
    {
        $profit = Analytics::profit($user, $from, $to);
        $feeRates = Analytics::shopFeeRates($user);
        // $from / $to are UTC instants (Dates::range); dates and day filters are WIB.
        $fromStr = $from->copy()->setTimezone(Dates::TZ)->toDateString();
        $toStr = $to->copy()->setTimezone(Dates::TZ)->toDateString();

        // Map ad spend per shop:item for the period
        $shopNames = MarketplaceShop::where('user_id', $user->id)->pluck('shop_name', 'id');
        $shopIds = MarketplaceShop::where('user_id', $user->id)->where('status', '!=', 'disconnected')->pluck('id');
        $adByItem = [];
        $campaigns = ShopeeAdCampaign::whereIn('marketplace_shop_id', $shopIds)
            ->withSum(['stats as expense' => fn ($q) => $q->whereBetween('date', [$fromStr, $toStr])], 'expense')
            ->get(['id', 'marketplace_shop_id', 'item_ids']);
        foreach ($campaigns as $c) {
            $ids = array_values(array_filter(array_map('intval', $c->item_ids ?? [])));
            if (! $ids) {
                continue;
            }
            $share = ((float) $c->expense * 1.11) / count($ids); // include PPN 11%
            foreach ($ids as $iid) {
                $k = "{$c->marketplace_shop_id}:{$iid}";
                $adByItem[$k] = ($adByItem[$k] ?? 0) + $share;
            }
        }

        // Lookup HPP by listing / product / SKU
        $listings = MarketplaceListing::where('user_id', $user->id)->with('product:id,cost_price')->get();
        $hppByKey = [];
        $hppBySku = [];
        foreach ($listings as $l) {
            $c = (int) ($l->cost_price ?: $l->product?->cost_price ?: 0);
            if ($c > 0) {
                $hppByKey["{$l->marketplace_shop_id}:{$l->item_id}:{$l->model_id}"] = $c;
                $hppByKey["{$l->marketplace_shop_id}:{$l->item_id}"] ??= $c;
                if ($l->sku) {
                    $hppBySku[mb_strtolower(trim($l->sku))] ??= $c;
                }
            }
        }
        $prodHpp = Product::where('user_id', $user->id)->pluck('cost_price', 'id');

        // Aggregate per product across orders in [$from, $to]
        $orders = Analytics::salesQuery($user, $from, $to)->get([
            'id', 'channel', 'marketplace_shop_id', 'product_id', 'items', 'quantity', 'total', 'shipping_cost', 'cod_fee', 'status',
        ]);
        $cancelledCount = Order::where('user_id', $user->id)
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->where(fn ($w) => $w->whereIn('status', ['cancelled', 'refunded'])->orWhere('external_status', 'CANCELLED'))
            ->count();
        $returnsCount = ShopeeReturn::where('user_id', $user->id)
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->count();

        $byProduct = [];
        $missingHppProducts = [];
        foreach ($orders as $o) {
            $feeRate = $o->channel === 'shopee' && $o->marketplace_shop_id
                ? ($feeRates[$o->marketplace_shop_id] ?? AdsGuard::DEFAULT_FEE_RATE)
                : 0.0;
            $items = is_array($o->items) && count($o->items) ? $o->items : [[
                'product_id' => $o->product_id,
                'item_id' => 0,
                'model_id' => 0,
                'name' => 'Produk #'.($o->product_id ?: $o->id),
                'sku' => null,
                'qty' => max(1, (int) $o->quantity),
                'subtotal' => max(0, (int) $o->total - (int) $o->shipping_cost - (int) $o->cod_fee),
            ]];
            foreach ($items as $it) {
                $qty = max(1, (int) ($it['qty'] ?? 1));
                $rev = (int) ($it['subtotal'] ?? ((int) ($it['discounted_price'] ?? $it['price'] ?? 0) * $qty));
                $iid = (int) ($it['item_id'] ?? 0);
                $mid = (int) ($it['model_id'] ?? 0);
                $pid = (int) ($it['product_id'] ?? 0);
                $sku = trim((string) ($it['sku'] ?? $it['model_sku'] ?? ''));
                $name = trim((string) ($it['name'] ?? ($sku ?: 'Produk')));
                $pKey = $iid > 0 ? "sp:{$o->marketplace_shop_id}:{$iid}" : ($pid > 0 ? "loc:{$pid}" : 'name:'.mb_strtolower($name));

                $unitHpp = 0;
                if ($iid > 0) {
                    $unitHpp = $hppByKey["{$o->marketplace_shop_id}:{$iid}:{$mid}"]
                        ?? $hppByKey["{$o->marketplace_shop_id}:{$iid}"]
                        ?? ($sku !== '' ? ($hppBySku[mb_strtolower($sku)] ?? 0) : 0);
                } elseif ($pid > 0) {
                    $unitHpp = (int) ($prodHpp[$pid] ?? 0);
                }
                if ($unitHpp === 0 && $rev > 0) {
                    $missingHppProducts[$pKey] = $name;
                }

                $byProduct[$pKey] ??= [
                    'key' => $pKey,
                    'name' => $name,
                    'sku' => $sku ?: null,
                    'shop_name' => $o->channel === 'shopee' ? ($shopNames[$o->marketplace_shop_id] ?? 'Shopee') : ucfirst((string) $o->channel),
                    'channel' => $o->channel,
                    'units' => 0,
                    'revenue' => 0,
                    'cogs' => 0,
                    'est_fees' => 0,
                    'ad_spend' => $iid > 0 ? (int) round($adByItem["{$o->marketplace_shop_id}:{$iid}"] ?? 0) : 0,
                    'has_hpp' => $unitHpp > 0,
                ];
                $byProduct[$pKey]['units'] += $qty;
                $byProduct[$pKey]['revenue'] += $rev;
                $byProduct[$pKey]['cogs'] += $unitHpp * $qty;
                $byProduct[$pKey]['est_fees'] += (int) round($rev * $feeRate);
                if ($unitHpp > 0) {
                    $byProduct[$pKey]['has_hpp'] = true;
                }
            }
        }

        $productRows = collect($byProduct)->map(function ($p) {
            $net = $p['revenue'] - $p['cogs'] - $p['est_fees'] - $p['ad_spend'];
            $margin = $p['revenue'] > 0 ? round(($net / $p['revenue']) * 100, 1) : 0;

            return $p + ['net_profit' => $net, 'margin_pct' => $margin];
        })->values();

        $lossProducts = $productRows->filter(fn ($p) => $p['has_hpp'] && ($p['net_profit'] < 0 || $p['margin_pct'] < 5))
            ->sortBy('net_profit')->take(10)->values()->all();
        $bestProducts = $productRows->filter(fn ($p) => $p['revenue'] > 0)
            ->sortByDesc('net_profit')->take(10)->values()->all();

        // Ads guard leaks + healthy ads for complete ads audit
        $guard = AdsGuard::evaluate($user, null, $fromStr, $toStr);
        $adsCampaigns = collect($guard['campaigns'] ?? [])
            ->take(20)
            ->map(fn ($c) => [
                'shop_name' => $shopNames[$c['shop_id']] ?? 'Shopee',
                'name' => $c['name'],
                'status' => $c['status'],
                'expense_real' => $c['expense_real'],
                'gmv' => $c['gmv'],
                'orders' => $c['orders'],
                'roas_real' => $c['roas_real'],
                'break_even' => $c['break_even'],
                'suggested_target' => $c['suggested_target'],
                'profit' => $c['profit'],
                'ctr' => $c['ctr'],
                'cr' => $c['cr'],
            ])->values()->all();
        $losingAds = collect($adsCampaigns)->whereIn('status', ['rugi', 'tanpa_penjualan', 'tipis'])->values()->all();

        // Per-shop breakdown (revenue, fees %, COGS, net profit, cancellations, returns)
        $cancelledByShop = Order::where('user_id', $user->id)
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->where(fn ($w) => $w->whereIn('status', ['cancelled', 'refunded'])->orWhere('external_status', 'CANCELLED'))
            ->selectRaw('marketplace_shop_id, COUNT(*) as cnt')
            ->groupBy('marketplace_shop_id')
            ->pluck('cnt', 'marketplace_shop_id');
        $returnsByShop = ShopeeReturn::where('user_id', $user->id)
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->selectRaw('marketplace_shop_id, COUNT(*) as cnt')
            ->groupBy('marketplace_shop_id')
            ->pluck('cnt', 'marketplace_shop_id');

        $byShop = collect($profit['by_channel'] ?? [])->map(function ($ch) use ($cancelledByShop, $returnsByShop) {
            $sid = $ch['shop_id'] ?? null;
            $rev = (int) ($ch['revenue'] ?? 0);
            $fees = (int) ($ch['fees'] ?? 0);

            return [
                'shop_name' => $ch['label'] ?? 'Toko',
                'orders' => (int) ($ch['orders'] ?? 0),
                'revenue' => $rev,
                'cogs' => (int) ($ch['cogs'] ?? 0),
                'platform_fees' => $fees,
                'fee_rate_pct' => $rev > 0 ? round(($fees / $rev) * 100, 1) : 0,
                'net_profit' => (int) ($ch['net_profit'] ?? 0),
                'margin_pct' => (float) ($ch['margin'] ?? 0),
                'cancelled_orders' => $sid ? (int) ($cancelledByShop[$sid] ?? 0) : 0,
                'returns_count' => $sid ? (int) ($returnsByShop[$sid] ?? 0) : 0,
            ];
        })->values()->all();

        // Stock masters & warehouse modal
        $masters = StockMaster::where('user_id', $user->id)
            ->with(['listings' => fn ($l) => $l->select('id', 'stock_master_id', 'product_id', 'price', 'cost_price')->with('product:id,cost_price')])
            ->get();
        $warehouseModal = 0;
        $lowStockSkus = [];
        foreach ($masters as $m) {
            $hpp = (int) ($m->listings->map(fn ($l) => (int) ($l->cost_price ?: $l->product?->cost_price))->filter()->max() ?: 0);
            if ($m->stock > 0 && $hpp > 0) {
                $warehouseModal += $m->stock * $hpp;
            }
            if ($m->stock <= 5) {
                $lowStockSkus[] = ['sku' => $m->sku, 'name' => $m->name, 'stock' => $m->stock];
            }
        }

        $t = $profit['summary'];
        $extra = self::extras($user, $from, $to, $profit);

        return [
            'period' => ['from' => $fromStr, 'to' => $toStr],
            'financials' => [
                'revenue' => (int) ($t['revenue'] ?? 0),
                'cogs' => (int) ($t['cogs'] ?? 0),
                'platform_fees' => (int) ($t['fees'] ?? 0),
                'ad_spend' => (int) ($t['ads'] ?? 0),
                'expenses' => (int) ($t['opex'] ?? 0),
                'net_profit' => (int) ($t['net_profit'] ?? 0),
                'net_margin_pct' => (float) ($t['margin'] ?? 0),
                'orders_count' => (int) ($t['orders'] ?? 0),
                'cancelled_orders' => $cancelledCount,
                'returns_count' => $returnsCount,
                'ad_waste_estimate' => (int) (($guard['summary']['loss'] ?? 0) + ($guard['summary']['wasted'] ?? 0)),
                'warehouse_modal' => $warehouseModal,
                'missing_hpp_count' => count($missingHppProducts),
            ],
            'by_shop' => $byShop,
            'loss_products' => $lossProducts,
            'best_products' => $bestProducts,
            'ads_campaigns' => $adsCampaigns,
            'losing_ads' => $losingAds,
            'low_stock_skus' => array_slice($lowStockSkus, 0, 10),
        ] + $extra;
    }

    /**
     * Context that makes the diagnosis concrete: the previous period (trend), weekly revenue/profit, and what
     * Pesanin's own checks found (Cek Dana, Cek Margin, batas kirim, prediksi stok, ulasan, COD/RTS, Meta Ads).
     * Each block is optional: a failing check never stops the diagnosis.
     */
    private static function extras(User $user, Carbon $from, Carbon $to, array $profit): array
    {
        $out = ['store' => ['track' => Plans::track($user), 'shopee_shops' => MarketplaceShop::where('user_id', $user->id)->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false)->count()]];
        $safe = function (string $key, callable $fn) use (&$out) {
            try {
                $v = $fn();
                if ($v !== null && $v !== []) {
                    $out[$key] = $v;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        };

        // Same-length period right before, to see the direction.
        $safe('previous_period', function () use ($user, $from, $to) {
            $days = (int) round($from->diffInHours($to) / 24);
            $pFrom = $from->copy()->subDays($days);
            $pTo = $from->copy()->subSecond();
            $p = Analytics::profit($user, $pFrom, $pTo)['summary'];

            return ['from' => $pFrom->copy()->setTimezone(Dates::TZ)->toDateString(), 'to' => $pTo->copy()->setTimezone(Dates::TZ)->toDateString(), 'revenue' => (int) ($p['revenue'] ?? 0), 'net_profit' => (int) ($p['net_profit'] ?? 0),
                'net_margin_pct' => (float) ($p['margin'] ?? 0), 'orders_count' => (int) ($p['orders'] ?? 0), 'ad_spend' => (int) ($p['ads'] ?? 0)];
        });

        // Weekly revenue / net (daily is too long for the prompt).
        $safe('weekly', fn () => collect($profit['daily'] ?? [])->groupBy(fn ($d) => Carbon::parse($d['date'])->startOfWeek()->toDateString())
            ->map(fn ($g, $w) => ['week_of' => $w, 'revenue' => (int) $g->sum('revenue'), 'net' => (int) $g->sum('net'), 'ads' => (int) $g->sum('ads')])->values()->all());

        // Cek Dana: money to claim / check (90 days).
        $safe('money_check', function () use ($user) {
            $m = MoneyCheck::scan($user);

            return ['total_at_stake' => $m['total'], 'kinds' => collect($m['kinds'])->where('count', '>', 0)
                ->map(fn ($k) => ['kind' => $k['title'], 'orders' => $k['count'], 'amount' => $k['amount'], 'examples' => collect($k['items'])->take(3)->pluck('order_sn')->filter()->values()])->values()->all()] ;
        });

        // Cek Margin: variants sold at a loss / thin margin at today's real price.
        $safe('margin_check', function () use ($user) {
            $m = MarginCheck::compute($user);
            if (! $m['summary']['checked']) {
                return null;
            }

            return $m['summary'] + ['worst' => collect($m['rows'])->whereIn('status', ['rugi', 'tipis'])->take(8)->map(fn ($r) => [
                'shop' => $r['shop_name'], 'product' => $r['name'].($r['variant'] ? " ({$r['variant']})" : ''), 'price' => $r['price'], 'hpp' => $r['hpp'],
                'fee' => $r['fee'], 'ads_per_unit' => $r['ad_per_unit'], 'margin' => $r['margin'], 'margin_pct' => $r['margin_pct'], 'min_safe_price' => $r['min_price'],
            ])->values()->all()];
        });

        // Batas kirim: orders about to be late (penalty points).
        $safe('ship_deadline', function () use ($user) {
            $d = collect(\App\Support\Shopee\ShipDeadline::due($user));

            return $d->isEmpty() ? null : ['late_now' => $d->where('late', true)->count(), 'due_within_6h' => $d->where('late', false)->where('hours_left', '<=', 6)->count(), 'due_within_24h' => $d->count()];
        });

        // Prediksi stok: SKUs running out / dead stock.
        $safe('stock_forecast', function () use ($user) {
            if (! StockMaster::where('user_id', $user->id)->exists()) {
                return null;
            }
            $sum = RestockForecast::summary($user);
            $cfg = RestockForecast::settings($user);
            $masters = StockMaster::where('user_id', $user->id)->get();
            $sold = RestockForecast::sold($masters->pluck('id')->all());
            $urgent = $masters->map(fn ($m) => ['sku' => $m->sku, 'name' => $m->name, 'stock' => $m->stock] + RestockForecast::forecast($m, $sold[$m->id] ?? 0, $cfg))
                ->whereIn('status', ['habis', 'kritis', 'segera'])->sortBy('days_left')->take(8)
                ->map(fn ($x) => array_intersect_key($x, array_flip(['sku', 'name', 'stock', 'per_day', 'days_left', 'restock_qty', 'status'])))->values()->all();

            return $sum + ['urgent' => $urgent];
        });

        // Ulasan: rating and the products collecting bad reviews.
        $safe('reviews', function () use ($user, $from, $to) {
            $q = \App\Models\ShopeeReview::where('user_id', $user->id)->whereBetween('reviewed_at', [$from->copy()->utc(), $to->copy()->utc()])->where('rating', '>', 0);
            $total = (clone $q)->count();
            if (! $total) {
                return null;
            }
            $worst = (clone $q)->where('rating', '<=', 3)->selectRaw('item_id, COUNT(*) as n, AVG(rating) as avg')->groupBy('item_id')->orderByDesc('n')->limit(5)->get();
            $names = MarketplaceListing::whereIn('item_id', $worst->pluck('item_id'))->pluck('name', 'item_id');

            return ['total' => $total, 'average' => round((float) (clone $q)->avg('rating'), 2), 'low_1_3' => (clone $q)->where('rating', '<=', 3)->count(),
                'unreplied_low' => (clone $q)->where('rating', '<=', 3)->whereNull('replied_at')->count(),
                'worst_products' => $worst->map(fn ($w) => ['product' => $names[$w->item_id] ?? "Produk {$w->item_id}", 'bad_reviews' => (int) $w->n,
                    'latest_complaints' => (clone $q)->where('item_id', $w->item_id)->where('rating', '<=', 3)->whereNotNull('comment')->latest('reviewed_at')->limit(2)->pluck('comment')->map(fn ($c) => mb_strimwidth($c, 0, 120, '…'))])->all()];
        });

        // Social & Web: COD returned to sender (RTS) and Meta Ads results.
        $safe('cod', function () use ($user, $from, $to) {
            $q = Order::where('user_id', $user->id)->where('channel', '!=', 'shopee')->where('payment_method', 'cod')->where('is_abandoned', false)
                ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()]);
            $n = (clone $q)->count();
            if (! $n) {
                return null;
            }
            $rts = (clone $q)->where('shipping_status', 'returned')->count();
            $shipped = (clone $q)->whereIn('shipping_status', ['picked_up', 'in_transit', 'delivered', 'returned'])->count();

            return ['cod_orders' => $n, 'shipped' => $shipped, 'returned_rts' => $rts, 'rts_rate_pct' => $shipped ? round($rts / $shipped * 100, 1) : 0,
                'cancelled' => (clone $q)->where('status', 'cancelled')->count()];
        });
        $safe('meta_ads', function () use ($user, $from, $to) {
            $rows = \App\Models\MetaAdInsight::where('user_id', $user->id)->whereBetween('date', [$from->copy()->setTimezone(Dates::TZ)->toDateString(), $to->copy()->setTimezone(Dates::TZ)->toDateString()])
                ->selectRaw('campaign_name, SUM(spend) spend, SUM(clicks) clicks, SUM(impressions) impressions, SUM(purchases) purchases, SUM(purchase_value) value, SUM(messages) messages')
                ->groupBy('campaign_name')->orderByDesc('spend')->limit(10)->get();
            if ($rows->isEmpty()) {
                return null;
            }

            return ['total_spend' => (int) $rows->sum('spend'), 'campaigns' => $rows->map(fn ($r) => ['campaign' => $r->campaign_name, 'spend' => (int) $r->spend,
                'purchases' => (int) $r->purchases, 'purchase_value' => (int) $r->value, 'messages' => (int) $r->messages,
                'roas' => $r->spend > 0 ? round($r->value / $r->spend, 2) : null, 'ctr_pct' => $r->impressions > 0 ? round($r->clicks / $r->impressions * 100, 2) : null])->all()];
        });

        return $out;
    }

    /** Pesanin pages an action may point to (the AI may only use these links). */
    public const LINKS = [
        '/marketplace/money-check' => 'Cek Dana (klaim ongkir, paket hilang, retur)',
        '/marketplace/margins' => 'Cek Margin (harga minimal per varian)',
        '/marketplace/ads' => 'Iklan Shopee (jeda iklan, ubah target ROAS, Analisa AI Iklan)',
        '/marketplace/products' => 'Semua Produk (isi HPP massal, ubah harga)',
        '/marketplace/stock' => 'Stok Master (prediksi & saran restock)',
        '/marketplace/promotions' => 'Promosi Shopee (diskon, voucher, paket)',
        '/marketplace/reviews' => 'Ulasan (balas ulasan buruk, rating per produk)',
        '/marketplace/health' => 'Kesehatan Akun (penalti, batas kirim)',
        '/marketplace/orders' => 'Pesanan Shopee',
        '/marketplace/product-performance' => 'Performa Produk',
        '/reports/profit' => 'Laporan Laba Rugi',
        '/reports/expenses' => 'Pengeluaran',
        '/orders' => 'Pesanan Media Sosial (risiko COD)',
        '/reports/meta-ads' => 'Laporan Meta Ads',
        '/landing-pages' => 'Landing Page',
        '/chat/quick-replies' => 'Balasan cepat & chat otomatis',
    ];

    /** Send the snapshot to Gemini and return the structured diagnosis. */
    public static function diagnose(string $apiKey, array $snapshot, ?string $question = null): array
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $links = collect(self::LINKS)->map(fn ($l, $u) => "- {$u} = {$l}")->implode("\n");
        $q = $question ? "\nPERTANYAAN PEMILIK TOKO (jawab di `custom_answer`): \"".str_replace('"', "'", $question).'"' : '';

        $prompt = <<<PROMPT
Anda konsultan keuangan & e-commerce senior untuk seller Indonesia (Shopee dan jualan lewat media sosial/website).
Periode analisis: {$snapshot['period']['from']} s/d {$snapshot['period']['to']}. Semua angka dalam Rupiah.

DATA BISNIS (JSON, nyata dari Pesanin):
{$json}
{$q}

Arti blok data:
- financials: laba rugi periode ini. previous_period: periode sebelumnya dengan panjang sama (untuk tren naik/turun). weekly: omzet & laba per minggu.
- by_shop: per toko. best_products / loss_products: laba per produk (setelah HPP, potongan Shopee, iklan).
- ads_campaigns / losing_ads: iklan Shopee (roas_real sudah termasuk PPN 11%, break_even = ROAS minimal agar tidak rugi).
- money_check: dana yang bisa diklaim/dicek (ongkir dibebankan ke penjual, ongkir retur, paket hilang, retur menunggu).
- margin_check: varian yang dijual rugi/tipis pada harga riil hari ini, beserta min_safe_price.
- ship_deadline: pesanan yang hampir/sudah lewat batas kirim (berisiko poin penalti).
- stock_forecast: SKU yang habis/kritis (restock_qty = saran jumlah) dan stok mati (dead_value = modal mengendap).
- reviews: rating & produk dengan ulasan buruk. cod: COD dan RTS (paket kembali) jalur media sosial. meta_ads: iklan Meta.
- financials.missing_hpp_count: produk tanpa HPP (laba belum akurat).

ATURAN WAJIB:
1. Gunakan HANYA angka yang ada di data. Jangan mengarang angka, nama toko, nama produk, atau nama iklan. Kalau data suatu blok tidak ada, jangan dibahas.
2. Setiap temuan & saran harus menyebut angkanya (Rp, %, ROAS, jumlah) dan nama toko/produk/iklan yang tepat.
3. Urutkan dari dampak Rupiah terbesar. Fokus pada hal yang bisa dikerjakan seller, bukan teori.
4. Kolom `link` HANYA boleh salah satu dari daftar ini (atau null):
{$links}
5. Bahasa Indonesia yang lugas, sapaan "Anda", tanpa basa-basi. Kalimat pendek.
6. Kalau financials.missing_hpp_count > 0, ingatkan bahwa laba belum akurat sampai HPP diisi.

Balas HANYA JSON valid dengan struktur ini:
{
  "health_score": 0-100,
  "health_label": "Sehat" | "Perlu Perhatian" | "Kritis",
  "executive_summary": "2-3 kalimat: kondisi laba, tren vs periode lalu, masalah terbesar.",
  "trend": {"direction": "naik" | "turun" | "stabil" | "tidak ada data", "summary": "Bandingkan omzet, laba, margin, iklan vs previous_period dengan angka."},
  "custom_answer": null atau "jawaban pertanyaan pemilik toko berbasis data",
  "quick_wins": [{"title": "Hal yang bisa dikerjakan HARI INI", "impact": "Rp... / alasan", "action": "langkah konkret", "link": "/..."}],
  "financial_leaks": [{"title": "...", "severity": "high" | "medium" | "low", "estimated_impact": "Rp...", "detail": "berbasis angka", "action": "...", "link": "/..."}],
  "shops_audit": [{"shop_name": "...", "status": "Sehat" | "Perlu Perhatian" | "Kritis", "summary": "omzet, laba, % potongan, batal/retur", "action": "...", "link": "/..."}],
  "ads_audit": [{"shop_name": "...", "campaign_name": "...", "verdict": "Matikan" | "Naikkan Target ROAS" | "Scale-Up" | "Perbaiki Iklan" | "Pantau", "metrics": "Biaya Rp..., ROAS riil ...x vs impas ...x, laba/rugi Rp...", "action": "..."}],
  "loss_products_advice": [{"product_name": "...", "issue": "rugi Rp... / margin ...% karena ...", "action": "naikkan harga ke Rp... (min_safe_price) / stop iklan / cek HPP", "link": "/..."}],
  "best_products_advice": [{"product_name": "...", "insight": "laba Rp..., margin ...%", "action": "scale-up & amankan stok", "link": "/..."}],
  "operations": [{"area": "Stok" | "Ulasan" | "Pengiriman" | "COD" | "Klaim Dana", "finding": "berbasis angka", "action": "...", "link": "/..."}],
  "action_plan_7_days": [{"day": "Hari 1", "task": "tugas konkret", "link": "/..." }]
}
Maksimal: quick_wins 4, financial_leaks 6, ads_audit 10, loss_products_advice 6, best_products_advice 5, operations 6, action_plan_7_days 7.
PROMPT;

        $d = Gemini::json($apiKey, $prompt, ['health_score', 'executive_summary']);

        // Only known Pesanin pages as links; normalize lists so the page never breaks on a missing key.
        $clean = function ($rows) {
            return collect(is_array($rows) ? $rows : [])->filter(fn ($r) => is_array($r) || is_string($r))->map(function ($r) {
                if (is_array($r) && array_key_exists('link', $r) && ! array_key_exists((string) $r['link'], self::LINKS)) {
                    $r['link'] = null;
                }

                return $r;
            })->values()->all();
        };
        foreach (['quick_wins', 'financial_leaks', 'shops_audit', 'ads_audit', 'loss_products_advice', 'best_products_advice', 'operations', 'action_plan_7_days'] as $k) {
            $d[$k] = $clean($d[$k] ?? []);
        }
        $d['health_score'] = max(0, min(100, (int) $d['health_score']));

        return $d;
    }
}
