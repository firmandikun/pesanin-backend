<?php

namespace App\Support;

use App\Models\AdSpend;
use App\Models\Expense;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopeeAdCampaign;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Omset, potongan marketplace, HPP, biaya iklan and laba bersih across every sales channel. */
class Analytics
{
    /** Shopee's Biaya Proses Pesanan per completed order (since 20 Jul 2025), field seller_order_processing_fee. */
    public const SHOPEE_ORDER_PROCESSING_FEE = 1250;

    public const AD_PLATFORMS = ['meta' => 'Meta Ads (FB/IG)', 'tiktok' => 'TikTok Ads', 'google' => 'Google Ads', 'shopee_ads' => 'Shopee Ads', 'other' => 'Lainnya'];

    /** Orders that count as a sale: not abandoned/cancelled/refunded, and paid or COD. */
    public static function salesQuery(User $user, Carbon $from, Carbon $to, ?string $channel = null, ?int $shopId = null)
    {
        return $user->orders()->getQuery()
            ->where('is_abandoned', false)
            ->where('status', '!=', 'cancelled')
            // A returned Shopee order still costs money (return shipping, refunds), so it stays in as a loss.
            ->where(fn ($q) => $q->where('status', '!=', 'refunded')->orWhere('channel', 'shopee'))
            ->where(fn ($q) => $q->where('payment_status', 'paid')->orWhere('payment_method', 'cod'))
            ->whereBetween('created_at', [$from, $to])
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId));
    }

    /** Per-order money breakdown. fee_final is false while Shopee has not released escrow. */
    public static function breakdown(Order $o, array $costs, array $shopFeeRate): array
    {
        $shopeeStatus = $o->external_status;
        if ($o->channel === 'shopee' && ($o->status === 'cancelled' || in_array($shopeeStatus, ['CANCELLED', 'IN_CANCEL'], true))) {
            // Cancelled: no sale, no fee, no goods out.
            return ['gmv' => 0, 'revenue' => 0, 'fees' => 0, 'cogs' => 0, 'fee_final' => true, 'missing_cost' => false];
        }
        if ($o->channel === 'shopee' && ($o->status === 'refunded' || $shopeeStatus === 'TO_RETURN')) {
            // Returned: the goods come back, so no omzet and no HPP; whatever Shopee settles (usually a
            // negative amount from return shipping) is the result of the order.
            $net = (int) ($o->escrow_amount ?? 0);

            return ['gmv' => 0, 'revenue' => 0, 'fees' => -$net, 'cogs' => 0, 'fee_final' => (bool) $o->escrow_final, 'missing_cost' => false];
        }
        if ($o->channel === 'shopee') {
            $revenue = (int) $o->subtotal;
            if ($o->escrow_amount !== null) {
                // Shopee's own income figure; an estimate until escrow is released.
                $fees = $revenue - (int) $o->escrow_amount;
                $final = (bool) $o->escrow_final;
            } else {
                // No income figure from Shopee yet: the shop's usual % fees plus the fixed processing fee.
                $fees = $revenue ? (int) round($revenue * ($shopFeeRate[$o->marketplace_shop_id] ?? 0)) + self::SHOPEE_ORDER_PROCESSING_FEE : 0;
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
        // spent_on is a WIB date; $from/$to are UTC moments, so compare WIB days (a UTC "today" also caught yesterday).
        [$fromDay, $toDay] = [Dates::day($from), Dates::day($to)];
        $ads = AdSpend::where('user_id', $user->id)->whereBetween('spent_on', [$fromDay, $toDay])
            ->when($channel === 'shopee' || $shopId, fn ($q) => $q->where('platform', 'shopee_ads'))
            ->when($channel && $channel !== 'shopee', fn ($q) => $q->where('platform', '!=', 'shopee_ads'))
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->get()
            // The Shopee Ads API reports spend before PPN, but the seller pays it on top of the ad credit.
            ->each(fn ($a) => $a->amount = self::adCost($a, $shops));

        // Biaya operasional (Pengeluaran) cut profit; a shop's costs belong to the Shopee channel,
        // business-wide costs (no shop) only show up in the overall figure.
        $opex = Expense::where('user_id', $user->id)->where('category', 'operational')
            ->whereBetween('spent_on', [$fromDay, $toDay])
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->when(! $shopId && $channel === 'shopee', fn ($q) => $q->whereNotNull('marketplace_shop_id'))
            ->when($channel && $channel !== 'shopee', fn ($q) => $q->whereNull('marketplace_shop_id'))
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
            $byChannel[$k] = ['key' => $k, 'label' => $label($k), 'ads' => 0, 'opex' => 0] + self::sum($g);
        }
        foreach ($ads->groupBy(fn ($a) => $a->platform === 'shopee_ads' ? 'shopee:'.$a->marketplace_shop_id : 'website:'.(Traffic::AD_PLATFORM[$a->platform] ?? 'other')) as $k => $g) {
            $byChannel[$k] ??= ['key' => $k, 'label' => $label($k), 'ads' => 0, 'opex' => 0] + self::sum(collect());
            $byChannel[$k]['ads'] = (int) $g->sum('amount');
        }
        foreach ($opex->whereNotNull('marketplace_shop_id')->groupBy(fn ($e) => 'shopee:'.$e->marketplace_shop_id) as $k => $g) {
            $byChannel[$k] ??= ['key' => $k, 'label' => $label($k), 'ads' => 0, 'opex' => 0] + self::sum(collect());
            $byChannel[$k]['opex'] = (int) $g->sum('amount');
        }
        $byChannel = collect($byChannel)->map(fn ($c) => self::finish($c))->sortByDesc('revenue')->values();

        $daily = [];
        foreach (Dates::days($from, $to) as $day) {
            $daily[$day] = ['date' => $day, 'revenue' => 0, 'net' => 0, 'ads' => 0, 'opex' => 0];
        }
        foreach ($rows as $r) {
            $k = Dates::day($r['order']->created_at);
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
        foreach ($opex as $e) {
            $k = $e->spent_on->toDateString();
            if (isset($daily[$k])) {
                $daily[$k]['opex'] += $e->amount;
                $daily[$k]['net'] -= $e->amount;
            }
        }

        $products = collect(self::productsAgg($rows, $costs))->map(fn ($p, $k) => $p + ['key' => $k])->sortByDesc('revenue')->values()->take(20);

        $summary = self::finish(self::sum($rows) + ['ads' => (int) $ads->sum('amount'), 'opex' => (int) $opex->sum('amount')]);
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

    /**
     * Financial ledger rows for a Shopee shop, separate from fulfilment actions.
     * Uses a base query (stdClass) without Eloquent hydration and defers loading `fee_breakdown` JSON
     * unless $withFeeBreakdown is true, so 20,000+ orders process in ~100ms with minimal memory.
     */
    public static function transactions(User $user, Carbon $from, Carbon $to, int $shopId, bool $withFeeBreakdown = false): Collection
    {
        $cols = [
            'id', 'marketplace_shop_id', 'channel', 'status', 'external_status', 'external_order_sn',
            'created_at', 'completed_at', 'customer_name', 'tracking_number', 'courier',
            'quantity', 'total', 'subtotal', 'escrow_amount', 'escrow_final',
            'marketplace_fee', 'seller_shipping_fee', 'items',
        ];
        if ($withFeeBreakdown) {
            $cols[] = 'fee_breakdown';
        }

        $rawOrders = $user->orders()->where('channel', 'shopee')->where('marketplace_shop_id', $shopId)
            ->where('is_abandoned', false)
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($c) use ($from, $to) {
                    $c->where('external_status', 'COMPLETED')
                        ->where(function ($w) use ($from, $to) {
                            $w->whereBetween('completed_at', [$from, $to])
                                ->orWhere(function ($fb) use ($from, $to) {
                                    $fb->whereNull('completed_at')->whereBetween('created_at', [$from, $to]);
                                });
                        });
                })->orWhere(function ($nc) use ($from, $to) {
                    $nc->where(function ($st) {
                        $st->whereNull('external_status')->orWhere('external_status', '!=', 'COMPLETED');
                    })->whereBetween('created_at', [$from, $to]);
                });
            })
            ->select($cols)
            ->orderByRaw('COALESCE(CASE WHEN external_status = "COMPLETED" THEN completed_at END, created_at) DESC')
            ->toBase()
            ->get();

        $costs = Product::withTrashed()->where('user_id', $user->id)->where('cost_price', '>', 0)
            ->pluck('cost_price', 'id')->map(fn ($v) => (int) $v)->all();
        self::$listingCosts = MarketplaceListing::where('user_id', $user->id)->where('marketplace_shop_id', $shopId)->where('cost_price', '>', 0)->get(['marketplace_shop_id', 'item_id', 'model_id', 'cost_price'])
            ->mapWithKeys(fn ($l) => [$l->marketplace_shop_id.':'.$l->item_id.':'.$l->model_id => (int) $l->cost_price])->all();
        $feeRates = self::shopFeeRates($user);
        $shopRate = (float) ($feeRates[$shopId] ?? 0);
        $adItems = self::adConversionMap($shopId, $from, $to);

        $out = [];
        foreach ($rawOrders as $row) {
            $shopeeStatus = $row->external_status;
            $rawItems = is_string($row->items) ? (json_decode($row->items, true) ?: []) : (is_array($row->items) ? $row->items : []);

            if ($row->status === 'cancelled' || $shopeeStatus === 'CANCELLED' || $shopeeStatus === 'IN_CANCEL') {
                $gmv = 0;
                $revenue = 0;
                $fees = 0;
                $cogs = 0;
                $feeFinal = true;
                $missingCost = false;
            } elseif ($row->status === 'refunded' || $shopeeStatus === 'TO_RETURN') {
                $net = (int) ($row->escrow_amount ?? 0);
                $gmv = 0;
                $revenue = 0;
                $fees = -$net;
                $cogs = 0;
                $feeFinal = (bool) $row->escrow_final;
                $missingCost = false;
            } else {
                $gmv = (int) $row->total;
                $revenue = (int) $row->subtotal;
                if ($row->escrow_amount !== null) {
                    $fees = $revenue - (int) $row->escrow_amount;
                    $feeFinal = (bool) $row->escrow_final;
                } else {
                    $fees = $revenue ? (int) round($revenue * $shopRate) + self::SHOPEE_ORDER_PROCESSING_FEE : 0;
                    $feeFinal = false;
                }
                $cogs = 0;
                $missingCost = false;
                foreach ($rawItems as $i) {
                    $itemId = $i['item_id'] ?? 0;
                    $modelId = $i['model_id'] ?? 0;
                    $c = self::$listingCosts[$shopId.':'.$itemId.':'.$modelId]
                        ?? self::$listingCosts[$shopId.':'.$itemId.':0']
                        ?? $costs[$i['product_id'] ?? 0] ?? null;
                    if ($c === null) {
                        $missingCost = true;
                    } else {
                        $cogs += $c * (int) ($i['qty'] ?? 1);
                    }
                }
            }

            $netRevenue = $revenue - $fees;
            $grossProfit = $netRevenue - $cogs;

            $seenItems = [];
            $isAdvertised = false;
            $adCost = 0;
            $items = [];
            foreach ($rawItems as $i) {
                $itemId = (int) ($i['item_id'] ?? 0);
                $ad = $itemId ? ($adItems[$itemId] ?? null) : null;
                $lineAdCost = 0;
                if ($ad) {
                    $isAdvertised = true;
                    if ($revenue > 0 && ! isset($seenItems[$itemId])) {
                        $lineAdCost = (int) round($ad['cost_per_conversion']);
                        $adCost += $lineAdCost;
                        $seenItems[$itemId] = true;
                    }
                }
                $items[] = $i + [
                    'is_advertised' => (bool) $ad,
                    'ad_campaign' => $ad['campaign'] ?? null,
                    'ad_status' => $ad['status'] ?? null,
                    'ad_cost_per_conversion' => $ad ? $ad['cost_per_conversion'] : 0,
                    'ad_conversions' => $ad['conversions'] ?? 0,
                    'ad_expense' => $ad['expense'] ?? 0,
                    'ad_cost' => $lineAdCost,
                ];
            }

            $profit = $grossProfit - $adCost;
            $fb = null;
            if ($withFeeBreakdown && ! empty($row->fee_breakdown)) {
                $fb = is_string($row->fee_breakdown) ? json_decode($row->fee_breakdown, true) : $row->fee_breakdown;
            }

            $out[] = [
                'id' => (int) $row->id,
                'order_sn' => $row->external_order_sn,
                'created_at' => $row->created_at,
                'completed_at' => $row->completed_at,
                'status' => $shopeeStatus,
                'customer_name' => $row->customer_name,
                'tracking_number' => $row->tracking_number,
                'courier' => $row->courier,
                'items' => $items,
                'quantity' => (int) $row->quantity,
                'gmv' => $gmv,
                'revenue' => $revenue,
                'fees' => $fees,
                'net_revenue' => $netRevenue,
                'cogs' => $cogs,
                'is_advertised' => $isAdvertised,
                'ad_cost' => $adCost,
                'gross_profit' => $grossProfit,
                'profit' => $profit,
                'margin' => $revenue ? round($profit / $revenue * 100, 1) : 0,
                'fee_final' => $feeFinal,
                'missing_cost' => $missingCost,
                'escrow_amount' => $row->escrow_amount !== null ? (int) $row->escrow_amount : null,
                'marketplace_fee' => (int) ($row->marketplace_fee ?? 0),
                'seller_shipping_fee' => (int) ($row->seller_shipping_fee ?? 0),
                'fee_breakdown' => $fb ?: self::fallbackFeeLinesFromRow($row, ['fees' => $fees, 'revenue' => $revenue]),
                'fee_source' => $fb ? 'shopee' : ($row->escrow_amount !== null ? 'summary' : 'estimate'),
            ];
        }

        return collect($out);
    }

    /** Attach fee_breakdown to a slice of transaction rows on demand (used for paginated responses or small datasets). */
    public static function attachFeeBreakdowns(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }
        $ids = $rows->pluck('id')->all();
        $rawBreakdowns = collect($ids)->chunk(500)->flatMap(fn ($chunk) => Order::whereIn('id', $chunk->all())->toBase()->pluck('fee_breakdown', 'id'))->all();

        return $rows->map(function (array $r) use ($rawBreakdowns) {
            $raw = $rawBreakdowns[$r['id']] ?? null;
            $fb = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            if ($fb) {
                $r['fee_breakdown'] = $fb;
                $r['fee_source'] = 'shopee';
            } else {
                $r['fee_breakdown'] = self::fallbackFeeLinesFromArr($r);
                $r['fee_source'] = $r['escrow_amount'] !== null ? 'summary' : 'estimate';
            }

            return $r;
        });
    }

    private static function fallbackFeeLinesFromRow(object $o, array $money): array
    {
        return self::fallbackFeeLinesFromArr([
            'fees' => $money['fees'],
            'revenue' => $money['revenue'],
            'escrow_amount' => $o->escrow_amount,
            'marketplace_fee' => (int) ($o->marketplace_fee ?? 0),
            'seller_shipping_fee' => (int) ($o->seller_shipping_fee ?? 0),
        ]);
    }

    private static function fallbackFeeLinesFromArr(array $r): array
    {
        if (! $r['fees']) {
            return [];
        }
        if (($r['escrow_amount'] ?? null) === null) {
            $pctFees = max(0, $r['fees'] - self::SHOPEE_ORDER_PROCESSING_FEE);
            $pct = $r['revenue'] ? round($pctFees / $r['revenue'] * 100, 1) : 0;

            return array_values(array_filter([
                ['key' => 'estimate', 'label' => "Estimasi biaya admin, layanan & ongkir (rata-rata toko {$pct}%)", 'amount' => -$pctFees],
                ['key' => 'seller_order_processing_fee', 'label' => 'Biaya Pemrosesan Pesanan', 'amount' => -min($r['fees'], self::SHOPEE_ORDER_PROCESSING_FEE)],
            ], fn ($l) => $l['amount'] !== 0));
        }
        $lines = array_values(array_filter([
            ['key' => 'commission_fee', 'label' => 'Biaya Admin & Layanan', 'amount' => -(int) ($r['marketplace_fee'] ?? 0)],
            ['key' => 'seller_shipping', 'label' => 'Ongkir ditanggung penjual', 'amount' => -(int) ($r['seller_shipping_fee'] ?? 0)],
        ], fn ($l) => $l['amount'] !== 0));
        $rest = -$r['fees'] - array_sum(array_column($lines, 'amount'));
        if ($rest) {
            $lines[] = ['key' => 'other', 'label' => $rest > 0 ? 'Subsidi / penyesuaian lainnya' : 'Potongan lainnya', 'amount' => $rest];
        }

        return $lines;
    }

    /**
     * Per-item_id Shopee Ads conversion metrics (Biaya per Konversi = expense / broad_order) from synced
     * ShopeeAdCampaign & ShopeeAdStat rows. Uses stats in [$from, $to] first, falling back to all synced stats.
     *
     * @return array<int, array{is_advertised: bool, campaign: string, status: string, expense: int, conversions: int, cost_per_conversion: float}>
     */
    public static function adConversionMap(int $shopId, Carbon $from, Carbon $to): array
    {
        [$fromDay, $toDay] = [Dates::day($from), Dates::day($to)];
        $campaigns = ShopeeAdCampaign::where('marketplace_shop_id', $shopId)->get(['id', 'campaign_id', 'name', 'status', 'item_ids']);
        if ($campaigns->isEmpty()) {
            return [];
        }
        $campIds = $campaigns->pluck('id')->all();
        $rangeSums = \App\Models\ShopeeAdStat::whereIn('shopee_ad_campaign_id', $campIds)
            ->whereBetween('date', [$fromDay, $toDay])
            ->selectRaw('shopee_ad_campaign_id, SUM(expense) as expense, SUM(orders) as orders')
            ->groupBy('shopee_ad_campaign_id')
            ->get()->keyBy('shopee_ad_campaign_id');
        $totalSums = \App\Models\ShopeeAdStat::whereIn('shopee_ad_campaign_id', $campIds)
            ->selectRaw('shopee_ad_campaign_id, SUM(expense) as expense, SUM(orders) as orders')
            ->groupBy('shopee_ad_campaign_id')
            ->get()->keyBy('shopee_ad_campaign_id');

        $shopActiveItems = null;
        $agg = [];
        foreach ($campaigns as $c) {
            $ids = array_values(array_filter(array_map('intval', $c->item_ids ?? [])));
            if (! $ids && $c->ad_type === 'auto') {
                $shopActiveItems ??= MarketplaceListing::where('marketplace_shop_id', $shopId)
                    ->where('status', 'NORMAL')
                    ->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $ids = $shopActiveItems;
            }
            if (! $ids) {
                continue;
            }
            $rStat = $rangeSums[$c->id] ?? null;
            $tStat = $totalSums[$c->id] ?? null;
            foreach ($ids as $itemId) {
                $agg[$itemId] ??= [
                    'campaigns' => [],
                    'ongoing' => false,
                    'range_expense' => 0.0,
                    'range_orders' => 0.0,
                    'total_expense' => 0.0,
                    'total_orders' => 0.0,
                ];
                $agg[$itemId]['campaigns'][] = $c->name ?: ($c->ad_type === 'auto' ? 'Iklan Produk Otomatis' : "Kampanye {$c->campaign_id}");
                if ($c->status === 'ongoing') {
                    $agg[$itemId]['ongoing'] = true;
                }
                $agg[$itemId]['range_expense'] += (float) ($rStat->expense ?? 0);
                $agg[$itemId]['range_orders'] += (float) ($rStat->orders ?? 0);
                $agg[$itemId]['total_expense'] += (float) ($tStat->expense ?? 0);
                $agg[$itemId]['total_orders'] += (float) ($tStat->orders ?? 0);
            }
        }

        $out = [];
        foreach ($agg as $itemId => $row) {
            $useRange = $row['range_orders'] > 0 || ($row['range_expense'] > 0 && $row['total_orders'] <= 0);
            $expense = $useRange ? $row['range_expense'] : $row['total_expense'];
            $orders = $useRange ? $row['range_orders'] : $row['total_orders'];
            $cpc = $orders > 0 ? round($expense / $orders, 2) : 0.0;
            $out[$itemId] = [
                'is_advertised' => true,
                'campaign' => implode(', ', array_unique($row['campaigns'])),
                'status' => $row['ongoing'] ? 'ongoing' : 'inactive',
                'expense' => (int) round($expense),
                'conversions' => (int) round($orders),
                'cost_per_conversion' => $cpc,
            ];
        }

        return $out;
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

    /**
     * Fee lines for orders whose Shopee breakdown isn't stored yet: what the older sync kept
     * (admin+layanan, ongkir borne by the seller), or the shop's average fee rate when Shopee
     * hasn't given an income figure at all.
     */
    private static function fallbackFeeLines(Order $o, array $money): array
    {
        if (! $money['fees']) {
            return [];
        }
        if ($o->escrow_amount === null) {
            $pctFees = max(0, $money['fees'] - self::SHOPEE_ORDER_PROCESSING_FEE);
            $pct = $money['revenue'] ? round($pctFees / $money['revenue'] * 100, 1) : 0;

            return array_values(array_filter([
                ['key' => 'estimate', 'label' => "Estimasi biaya admin, layanan & ongkir (rata-rata toko {$pct}%)", 'amount' => -$pctFees],
                ['key' => 'seller_order_processing_fee', 'label' => 'Biaya Pemrosesan Pesanan', 'amount' => -min($money['fees'], self::SHOPEE_ORDER_PROCESSING_FEE)],
            ], fn ($l) => $l['amount'] !== 0));
        }
        $lines = array_values(array_filter([
            ['key' => 'commission_fee', 'label' => 'Biaya Admin & Layanan', 'amount' => -(int) $o->marketplace_fee],
            ['key' => 'seller_shipping', 'label' => 'Ongkir ditanggung penjual', 'amount' => -(int) $o->seller_shipping_fee],
        ], fn ($l) => $l['amount'] !== 0));
        $rest = -$money['fees'] - array_sum(array_column($lines, 'amount'));
        if ($rest) {
            $lines[] = ['key' => 'other', 'label' => $rest > 0 ? 'Subsidi / penyesuaian lainnya' : 'Potongan lainnya', 'amount' => $rest];
        }

        return $lines;
    }

    /** Ad spend as the seller pays it: Shopee Ads plus the shop's PPN (default 11%). */
    public static function adCost(AdSpend $a, Collection $shops): int
    {
        if ($a->platform !== 'shopee_ads' || ! $a->synced) {
            return (int) $a->amount;
        }
        $ppn = (float) ($shops[$a->marketplace_shop_id]->ads_ppn ?? 11);

        return (int) round($a->amount * (1 + $ppn / 100));
    }

    /** Shopee % fee ratio per shop (excluding the fixed processing fee), learned from orders that already have Shopee's income figure. */
    public static function shopFeeRates(User $user): array
    {
        return Order::where('user_id', $user->id)->where('channel', 'shopee')->whereNotNull('escrow_amount')->where('subtotal', '>', 0)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->selectRaw('marketplace_shop_id, SUM(CAST(subtotal AS SIGNED) - escrow_amount - ?) * 1.0 / SUM(subtotal) r', [self::SHOPEE_ORDER_PROCESSING_FEE])->groupBy('marketplace_shop_id')
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
        $c['net_profit'] = $c['gross_profit'] - $c['ads'] - ($c['opex'] ?? 0);
        $c['margin'] = $c['revenue'] ? round($c['net_profit'] / $c['revenue'] * 100, 1) : 0;
        $c['roas'] = $c['ads'] ? round($c['revenue'] / $c['ads'], 2) : null;

        return $c;
    }
}
