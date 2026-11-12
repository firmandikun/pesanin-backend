<?php

namespace App\Support\Shopee;

use App\Models\AdSpend;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopeeAdCampaign;
use App\Models\ShopeeAdStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Pulls a connected Shopee shop's listings, orders, fees and ad spend into Pesanin. */
class ShopeeSync
{
    public function __construct(private ShopeeClient $api = new ShopeeClient) {}

    public function shopInfo(MarketplaceShop $shop): void
    {
        $info = $this->api->shopInfo($shop);
        $shop->update(['shop_name' => $info['shop_name'] ?? $shop->shop_name, 'region' => $info['region'] ?? $shop->region]);
    }

    /** @return int listings upserted */
    public function products(MarketplaceShop $shop): int
    {
        $count = 0;
        $offset = 0;
        $skuMap = Product::where('user_id', $shop->user_id)->whereNotNull('sku')->pluck('id', 'sku');
        do {
            $page = $this->api->itemList($shop, $offset);
            $ids = array_column($page['item'] ?? [], 'item_id');
            foreach (array_chunk($ids, 50) as $chunk) {
                foreach ($this->api->itemBaseInfo($shop, $chunk) as $item) {
                    $count += $this->upsertItem($shop, $item, $skuMap->all());
                }
            }
            $offset = $page['next_offset'] ?? 0;
        } while (! empty($page['has_next_page']));
        $shop->update(['products_synced_at' => now(), 'last_error' => null]);

        return $count;
    }

    private function upsertItem(MarketplaceShop $shop, array $item, array $skuMap): int
    {
        $image = $item['image']['image_url_list'][0] ?? null;
        $rows = [];
        if (! empty($item['has_model'])) {
            $models = $this->api->modelList($shop, $item['item_id']);
            $tiers = $models['tier_variation'] ?? [];
            foreach ($models['model'] ?? [] as $m) {
                $name = collect($m['tier_index'] ?? [])->map(fn ($idx, $t) => $tiers[$t]['option_list'][$idx]['option'] ?? null)->filter()->implode(', ');
                $rows[] = [$m['model_id'], $name ?: null, $m['model_sku'] ?? null, $m['price_info'][0]['current_price'] ?? 0, $m['stock_info_v2']['summary_info']['total_available_stock'] ?? 0];
            }
        } else {
            $rows[] = [0, null, $item['item_sku'] ?? null, $item['price_info'][0]['current_price'] ?? 0, $item['stock_info_v2']['summary_info']['total_available_stock'] ?? 0];
        }

        foreach ($rows as [$modelId, $modelName, $sku, $price, $stock]) {
            $listing = MarketplaceListing::firstOrNew(['marketplace_shop_id' => $shop->id, 'item_id' => $item['item_id'], 'model_id' => $modelId]);
            $listing->user_id = $shop->user_id;
            $listing->fill([
                'name' => $item['item_name'], 'model_name' => $modelName, 'sku' => $sku ?: null,
                'price' => (int) round($price), 'stock' => (int) $stock, 'status' => $item['item_status'] ?? 'NORMAL', 'image' => $image,
            ]);
            // Auto-link to a Pesanin product when the SKU matches, unless the seller already mapped it.
            if (! $listing->product_id && $sku && isset($skuMap[$sku])) {
                $listing->product_id = $skuMap[$sku];
            }
            $listing->save();
        }

        return count($rows);
    }

    /** @return int orders upserted. Shopee limits each list call to a 15-day window. */
    public function orders(MarketplaceShop $shop, int $days = 15): int
    {
        $count = 0;
        $end = now();
        $start = $shop->orders_synced_at ? $shop->orders_synced_at->copy()->subDay() : now()->subDays($days);
        for ($from = $start->copy(); $from < $end; $from->addDays(15)) {
            $to = $from->copy()->addDays(15)->min($end);
            $cursor = '';
            do {
                $page = $this->api->orderList($shop, $from->timestamp, $to->timestamp, $cursor);
                $sns = array_column($page['order_list'] ?? [], 'order_sn');
                foreach (array_chunk($sns, 50) as $chunk) {
                    foreach ($this->api->orderDetail($shop, $chunk) as $detail) {
                        $this->upsertOrder($shop, $detail);
                        $count++;
                    }
                }
                $cursor = $page['next_cursor'] ?? '';
            } while (! empty($page['more']));
        }
        $shop->update(['orders_synced_at' => $end, 'last_error' => null]);
        $this->settle($shop);

        return $count;
    }

    /**
     * Fast poll (every 30s): orders created or changed in the last few minutes, by update_time.
     * Keeps new orders and status changes close to real time without Live Push.
     */
    public function recent(MarketplaceShop $shop, int $minutes = 5): int
    {
        $count = 0;
        $cursor = '';
        do {
            $page = $this->api->orderList($shop, now()->subMinutes($minutes)->timestamp, now()->timestamp, $cursor, 'update_time');
            $sns = array_column($page['order_list'] ?? [], 'order_sn');
            foreach (array_chunk($sns, 50) as $chunk) {
                foreach ($this->api->orderDetail($shop, $chunk) as $detail) {
                    $this->upsertOrder($shop, $detail);
                    $count++;
                }
            }
            $cursor = $page['next_cursor'] ?? '';
        } while (! empty($page['more']));

        return $count;
    }

    public static function arrangedKey(int $orderId): string
    {
        return "shopee:arranged:{$orderId}";
    }

    /** Shopee accepted ship_order: show the order as arranged now, whatever the next few reads say. */
    public static function markArranged(Order $order): void
    {
        Cache::put(self::arrangedKey($order->id), true, now()->addMinutes(15));
        if ($order->external_status === 'READY_TO_SHIP') {
            $order->update(['external_status' => 'PROCESSED', 'status' => 'processing', 'shipping_status' => 'not_shipped']);
        }
    }

    public function order(MarketplaceShop $shop, string $orderSn): ?Order
    {
        $detail = $this->api->orderDetail($shop, [$orderSn])[0] ?? null;

        return $detail ? $this->upsertOrder($shop, $detail) : null;
    }

    public function upsertOrder(MarketplaceShop $shop, array $d): Order
    {
        $listings = MarketplaceListing::where('marketplace_shop_id', $shop->id)->get()->keyBy(fn ($l) => $l->item_id.':'.$l->model_id);
        $items = collect($d['item_list'] ?? [])->map(fn ($i) => [
            'item_id' => $i['item_id'], 'model_id' => $i['model_id'] ?? 0,
            'name' => $i['item_name'], 'variant' => $i['model_name'] ?? null, 'sku' => ($i['model_sku'] ?? '') ?: (($i['item_sku'] ?? '') ?: null),
            'qty' => (int) ($i['model_quantity_purchased'] ?? 1), 'price' => (int) round($i['model_discounted_price'] ?? $i['model_original_price'] ?? 0),
            'image' => $i['image_info']['image_url'] ?? null,
            'product_id' => $listings[$i['item_id'].':'.($i['model_id'] ?? 0)]->product_id ?? $listings[$i['item_id'].':0']->product_id ?? null,
        ])->values();

        // Right after "Atur pengiriman" Shopee can still report READY_TO_SHIP for a while;
        // don't let that stale read move an arranged order back to "Perlu dikirim".
        $existing = Order::where('marketplace_shop_id', $shop->id)->where('external_order_sn', $d['order_sn'])->first();
        if (($d['order_status'] ?? null) === 'READY_TO_SHIP' && $existing && Cache::has(self::arrangedKey($existing->id))) {
            $d['order_status'] = 'PROCESSED';
        }
        [$status, $shipping] = $this->mapStatus($d['order_status'] ?? 'UNPAID');
        $addr = $d['recipient_address'] ?? [];
        $subtotal = $items->sum(fn ($i) => $i['qty'] * $i['price']);

        $order = Order::firstOrNew(['marketplace_shop_id' => $shop->id, 'external_order_sn' => $d['order_sn']]);
        $order->user_id = $shop->user_id;
        $order->fill([
            'code' => $order->code ?? 'SP'.$d['order_sn'],
            'channel' => 'shopee', 'source' => 'marketplace', 'external_status' => $d['order_status'] ?? null,
            'items' => $items->all(), 'product_id' => $items->firstWhere('product_id')['product_id'] ?? null,
            'customer_name' => $addr['name'] ?? ($d['buyer_username'] ?? null), 'phone' => $addr['phone'] ?? null,
            'address' => $addr['full_address'] ?? null, 'province' => $addr['state'] ?? null, 'city' => $addr['city'] ?? null,
            'district' => $addr['district'] ?? null, 'postal_code' => $addr['zipcode'] ?? null,
            'variant' => $items->count() > 1 ? $items->count().' produk' : ($items[0]['variant'] ?? null),
            'quantity' => $items->sum('qty'), 'unit_price' => $items[0]['price'] ?? 0, 'subtotal' => $subtotal,
            'shipping_cost' => (int) round($d['actual_shipping_fee'] ?? $d['estimated_shipping_fee'] ?? 0),
            'total' => (int) round($d['total_amount'] ?? $subtotal),
            'payment_method' => 'marketplace', 'payment_status' => ! empty($d['pay_time']) || ! empty($d['cod']) ? 'paid' : 'unpaid',
            'status' => $status, 'shipping_status' => $shipping, 'courier' => $d['shipping_carrier'] ?? null,
            'paid_at' => ! empty($d['pay_time']) ? Carbon::createFromTimestamp($d['pay_time']) : null,
        ]);
        if (! $order->exists && ! empty($d['create_time'])) {
            $order->created_at = Carbon::createFromTimestamp($d['create_time']);
        }
        $order->save();

        // Tracking numbers are issued asynchronously after the shipment is arranged.
        if (! $order->tracking_number && in_array($d['order_status'] ?? '', ['PROCESSED', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true)) {
            try {
                if ($tn = $this->api->trackingNumber($shop, $d['order_sn'])) {
                    $order->update(['tracking_number' => $tn]);
                }
            } catch (ShopeeException) {
                // not issued yet; the next sync retries
            }
        }

        // A failed pickup comes back as RETRY_SHIP; keep Shopee's reason so the seller sees why.
        if (($d['order_status'] ?? '') === 'RETRY_SHIP') {
            try {
                $last = collect($this->api->trackingInfo($shop, $d['order_sn'])['tracking_info'] ?? [])->sortByDesc('update_time')->first();
                $order->update(['external_note' => $last ? mb_substr(trim($last['description'] ?? ''), 0, 250) : null]);
            } catch (ShopeeException) {
                // keep the previous note
            }
        } elseif ($order->external_note) {
            $order->update(['external_note' => null]);
        }

        // Shopee estimates the seller's income as soon as an order is paid and finalises it when escrow is
        // released (COMPLETED). Refresh the estimate whenever the status moves, until it is final.
        $status = $d['order_status'] ?? '';
        if (! $shop->is_demo && ! $order->escrow_final && $order->payment_status === 'paid' && in_array($status, self::INCOME_STATUSES, true)
            && ($order->escrow_amount === null || $status === 'COMPLETED' || $existing?->external_status !== $status)) {
            $this->fees($shop, $order);
        }

        return $order;
    }

    /** Statuses for which Shopee returns an (estimated or final) income breakdown. */
    public const INCOME_STATUSES = ['READY_TO_SHIP', 'PROCESSED', 'RETRY_SHIP', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED', 'TO_RETURN'];

    /** Shopee order_income fields shown to the seller: key => [label, +1 credit / -1 deduction]. */
    public const FEE_LINES = [
        'commission_fee' => ['Biaya Admin', -1],
        'service_fee' => ['Biaya Layanan', -1],
        'seller_order_processing_fee' => ['Biaya Pemrosesan Pesanan', -1],
        'seller_transaction_fee' => ['Biaya Transaksi', -1],
        'order_ams_commission_fee' => ['Biaya Admin AMS (Affiliate)', -1],
        'campaign_fee' => ['Biaya Kampanye', -1],
        'actual_shipping_fee' => ['Ongkir Aktual', -1],
        'shopee_shipping_rebate' => ['Subsidi Ongkir Shopee', 1],
        'buyer_paid_shipping_fee' => ['Ongkir Dibayar Pembeli', 1],
        'shipping_fee_discount_from_3pl' => ['Diskon Ongkir Kurir', 1],
        'seller_shipping_discount' => ['Subsidi Ongkir dari Penjual', -1],
        'reverse_shipping_fee' => ['Ongkir Retur', -1],
        'delivery_seller_protection_fee_premium_amount' => ['Premi Proteksi Pengiriman', -1],
        'shipping_seller_protection_fee_amount' => ['Proteksi Ongkir Penjual', -1],
        'rsf_seller_protection_fee_claim_amount' => ['Klaim Proteksi Ongkir Retur', 1],
        'final_return_to_seller_shipping_fee' => ['Ongkir Pengembalian ke Penjual', -1],
        'fbs_fee' => ['Biaya Fulfilment (FBS)', -1],
        'ads_escrow_top_up_fee_or_technical_support_fee' => ['Top Up Iklan / Dukungan Teknis', -1],
        'sspl_fee' => ['Biaya SSPL', -1],
        'buyer_paid_packaging_fee' => ['Biaya Kemasan Dibayar Pembeli', 1],
        'voucher_from_seller' => ['Voucher Penjual', -1],
        'seller_coin_cash_back' => ['Cashback Koin Penjual', -1],
        'seller_return_refund' => ['Pengembalian Dana ke Pembeli', -1],
        'drc_adjustable_refund' => ['Penyesuaian Refund', -1],
        'withholding_tax' => ['Pajak Pemotongan', -1],
        'escrow_tax' => ['Pajak', -1],
        'seller_lost_compensation' => ['Kompensasi Barang Hilang', 1],
    ];

    /**
     * Shopee fee lines of an order, signed, plus a reconciling "Lainnya" line so they add up to
     * (escrow - product revenue).
     *
     * @return list<array{key: string, label: string, amount: int}>
     */
    public static function feeLines(array $income, int $revenue): array
    {
        $lines = [];
        foreach (self::FEE_LINES as $key => [$label, $sign]) {
            $v = (int) round(abs((float) ($income[$key] ?? 0)));
            if ($v) {
                $lines[] = ['key' => $key, 'label' => $label, 'amount' => $sign * $v];
            }
        }
        $rest = ((int) round($income['escrow_amount'] ?? 0) - $revenue) - array_sum(array_column($lines, 'amount'));
        if ($rest) {
            $lines[] = ['key' => 'other', 'label' => $rest > 0 ? 'Penyesuaian lainnya' : 'Potongan lainnya', 'amount' => $rest];
        }

        return $lines;
    }

    public function fees(MarketplaceShop $shop, Order $order): void
    {
        try {
            $income = $this->api->escrowDetail($shop, $order->external_order_sn)['order_income'] ?? null;
        } catch (ShopeeException) {
            return; // escrow not available yet
        }
        if (! $income) {
            return;
        }
        $order->update([
            'marketplace_fee' => (int) round(($income['commission_fee'] ?? 0) + ($income['service_fee'] ?? 0) + ($income['seller_transaction_fee'] ?? 0)
                + ($income['seller_order_processing_fee'] ?? 0) + ($income['order_ams_commission_fee'] ?? 0)),
            'seller_shipping_fee' => (int) max(0, round(-($income['final_shipping_fee'] ?? 0))),
            'escrow_amount' => (int) round($income['escrow_amount'] ?? 0),
            // Only a completed order's income is final; before that Shopee returns an estimate.
            'escrow_final' => $order->external_status === 'COMPLETED',
            'fee_breakdown' => self::feeLines($income, (int) $order->subtotal),
        ]);
    }

    /**
     * Completed orders whose income was only an estimate get the final amount, and orders synced before
     * fee lines were stored get their breakdown (oldest first, a batch per sync).
     */
    public function settle(MarketplaceShop $shop, int $limit = 50): int
    {
        if ($shop->is_demo) {
            return 0;
        }
        $orders = Order::where('marketplace_shop_id', $shop->id)->where('payment_status', 'paid')->where('escrow_final', false)
            ->whereIn('external_status', self::INCOME_STATUSES)
            ->where(fn ($q) => $q->where('external_status', 'COMPLETED')->orWhereNull('fee_breakdown'))
            ->oldest()->limit($limit)->get();
        foreach ($orders as $order) {
            $this->fees($shop, $order);
        }

        return $orders->count();
    }

    /** @return int days of ad spend stored */
    /** Shopee caps the range at one month, so pull at most the last 29 days. */
    public function ads(MarketplaceShop $shop, int $days = 29): int
    {
        $days = min($days, 29);
        $rows = $this->api->adsDailyPerformance($shop, now()->subDays($days)->format('d-m-Y'), now()->format('d-m-Y'));
        foreach ($rows as $r) {
            AdSpend::updateOrCreate(
                ['user_id' => $shop->user_id, 'platform' => 'shopee_ads', 'marketplace_shop_id' => $shop->id, 'spent_on' => Carbon::createFromFormat('d-m-Y', $r['date'])->toDateString(), 'synced' => true],
                ['amount' => (int) round($r['expense'] ?? 0), 'campaign' => 'Shopee Ads (otomatis)'],
            );
        }

        $shop->update(['ads_balance' => $this->api->adsBalance($shop), 'ads_balance_at' => now()]);
        $this->adCampaigns($shop, $days);

        return count($rows);
    }

    /** Product-ads campaigns, the items they promote, and their daily spend/results. */
    public function adCampaigns(MarketplaceShop $shop, int $days = 29): int
    {
        $ids = $this->api->adCampaignIds($shop);
        if (! $ids) {
            return 0;
        }
        $byId = [];
        foreach ($this->api->adCampaignSettings($shop, $ids) as $c) {
            $info = $c['common_info'] ?? $c;
            $budget = $info['campaign_budget'] ?? null;
            $byId[(int) $c['campaign_id']] = ShopeeAdCampaign::updateOrCreate(
                ['marketplace_shop_id' => $shop->id, 'campaign_id' => (int) $c['campaign_id']],
                [
                    'user_id' => $shop->user_id,
                    'name' => $info['ad_name'] ?? $info['campaign_name'] ?? null,
                    'ad_type' => $info['ad_type'] ?? $c['ad_type'] ?? null,
                    'bidding_method' => $info['bidding_method'] ?? null,
                    'status' => $info['campaign_status'] ?? null,
                    'placement' => $info['campaign_placement'] ?? null,
                    'budget' => is_numeric($budget) ? (int) round($budget) : null,
                    // Auto-selection campaigns may list their products only in auto_product_ads_info.
                    'item_ids' => collect($info['item_id_list'] ?? [])->merge(collect($c['auto_product_ads_info'] ?? [])->pluck('item_id'))
                        ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
                ],
            );
        }
        $from = now()->subDays(min($days, 29))->format('d-m-Y');
        foreach ($this->api->adCampaignDailyPerformance($shop, array_keys($byId), $from, now()->format('d-m-Y')) as $c) {
            $campaign = $byId[(int) $c['campaign_id']] ?? null;
            foreach ($campaign ? ($c['metrics_list'] ?? []) : [] as $m) {
                ShopeeAdStat::updateOrCreate(
                    ['shopee_ad_campaign_id' => $campaign->id, 'date' => Carbon::createFromFormat('d-m-Y', $m['date'])->toDateString()],
                    [
                        'impressions' => (int) ($m['impression'] ?? 0), 'clicks' => (int) ($m['clicks'] ?? 0),
                        'expense' => (int) round($m['expense'] ?? 0), 'gmv' => (int) round($m['broad_gmv'] ?? 0),
                        'orders' => (int) ($m['broad_order'] ?? 0), 'items_sold' => (int) ($m['broad_order_amount'] ?? 0),
                    ],
                );
            }
        }

        return count($byId);
    }

    /** Shopee order_status → [status, shipping_status]. */
    private function mapStatus(string $s): array
    {
        return match ($s) {
            'UNPAID' => ['pending', 'not_shipped'],
            'READY_TO_SHIP', 'PROCESSED', 'RETRY_SHIP' => ['processing', 'not_shipped'],
            'SHIPPED', 'TO_CONFIRM_RECEIVE' => ['processing', 'in_transit'],
            'COMPLETED' => ['completed', 'delivered'],
            'TO_RETURN' => ['refunded', 'returned'],
            'IN_CANCEL', 'CANCELLED' => ['cancelled', 'not_shipped'],
            default => ['pending', 'not_shipped'],
        };
    }
}
