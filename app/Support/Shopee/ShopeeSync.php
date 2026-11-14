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
use Illuminate\Support\Str;

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

    /**
     * @return int orders upserted. Shopee limits each list call to a 15-day window.
     * First run pulls the last $days by create_time; later runs pull everything *changed* since the last run
     * (update_time), so status changes of older orders are not missed. Open orders are then reconciled.
     */
    public function orders(MarketplaceShop $shop, int $days = 15): int
    {
        @set_time_limit(0);
        $count = 0;
        $end = now();
        $first = ! $shop->orders_synced_at;
        $start = $first ? now()->subDays($days) : $shop->orders_synced_at->copy()->subHour();
        for ($from = $start->copy(); $from < $end; $from->addDays(15)) {
            $to = $from->copy()->addDays(15)->min($end);
            $count += $this->pull($shop, $from->timestamp, $to->timestamp, $first ? 'create_time' : 'update_time');
        }
        $count += $this->reconcileOpen($shop);
        // Bookings and returns are optional modules; never fail the order sync for them.
        foreach (['bookings', 'returns'] as $extra) {
            try {
                $this->{$extra}($shop);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Shopee {$extra} {$shop->id}: {$e->getMessage()}");
            }
        }
        $update = ['orders_synced_at' => $end, 'last_error' => null];
        if ($first) {
            $update['orders_backfilled_from'] = $start->toDateString();
        }
        $shop->update($update);
        $this->settle($shop);

        return $count;
    }

    /**
     * Pull older orders by create_time so a report for a past period matches Seller Centre. Only the range the
     * shop has not backfilled yet is fetched; upsert dedupes. @return int orders upserted.
     */
    public function backfill(MarketplaceShop $shop, Carbon $from): int
    {
        @set_time_limit(0);
        $from = $from->copy()->startOfDay();
        // Shopee keeps about 2 years of orders; never ask earlier. (Orders can predate when the shop was connected,
        // so we must NOT clamp by the Pesanin record's created_at.)
        $floor = now()->subYears(2)->startOfDay();
        if ($from->lt($floor)) {
            $from = $floor;
        }
        $covered = $shop->orders_backfilled_from?->copy()->startOfDay() ?? now()->subDays(15)->startOfDay();
        if ($from->gte($covered)) {
            return 0;
        }
        $count = 0;
        for ($w = $from->copy(); $w < $covered; $w->addDays(15)) {
            $count += $this->pull($shop, $w->timestamp, $w->copy()->addDays(15)->min($covered)->timestamp, 'create_time');
        }
        $shop->update(['orders_backfilled_from' => $from->toDateString()]);
        $this->settle($shop, 200);

        return $count;
    }

    /** List orders in one window (optionally one status) and upsert their details. */
    private function pull(MarketplaceShop $shop, int $from, int $to, string $field = 'create_time', ?string $status = null, ?array &$seen = null): int
    {
        $count = 0;
        $cursor = '';
        do {
            $page = $this->api->orderList($shop, $from, $to, $cursor, $field, $status);
            $sns = array_column($page['order_list'] ?? [], 'order_sn');
            if ($seen !== null) {
                array_push($seen, ...$sns);
            }
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

    /**
     * Make "Perlu dikirim / Siap diserahkan" match Seller Centre: pull every open order Shopee lists (last 30 days),
     * then refresh local open orders Shopee no longer lists (they moved on to shipped, cancelled, …).
     */
    public function reconcileOpen(MarketplaceShop $shop): int
    {
        // Statuses Shopee accepts as a get_order_list filter, and how far back to look. Parcels still on the way
        // can be weeks old, so "Dikirim" looks back further than the to-ship tabs.
        // RETRY_SHIP / TO_CONFIRM_RECEIVE / TO_RETURN are not accepted as filters; local ones are refreshed by detail.
        $listable = ['READY_TO_SHIP' => 30, 'PROCESSED' => 30, 'SHIPPED' => 60];
        $open = ['READY_TO_SHIP', 'PROCESSED', 'RETRY_SHIP', 'SHIPPED', 'TO_CONFIRM_RECEIVE'];
        $listed = []; // order_sn => status Shopee reports
        foreach ($listable as $status => $days) {
            for ($end = now(), $from = $end->copy()->subDays($days); $from < $end; $from->addDays(15)) {
                $cursor = '';
                do {
                    $page = $this->api->orderList($shop, $from->timestamp, $from->copy()->addDays(15)->min($end)->timestamp, $cursor, 'create_time', $status);
                    foreach ($page['order_list'] ?? [] as $o) {
                        $listed[$o['order_sn']] = $o['order_status'] ?? $status;
                    }
                    $cursor = $page['next_cursor'] ?? '';
                } while (! empty($page['more']));
            }
        }
        $local = Order::where('marketplace_shop_id', $shop->id)->whereNotNull('external_order_sn')
            ->whereIn('external_order_sn', array_keys($listed) ?: [''])->pluck('external_status', 'external_order_sn');
        // Missing locally, or local status differs from Shopee's.
        $refresh = collect($listed)->filter(fn ($st, $sn) => ($local[$sn] ?? null) !== $st)->keys()->all();
        // Local open orders Shopee no longer lists under an open status moved on (delivered, cancelled, …).
        $stale = Order::where('marketplace_shop_id', $shop->id)->whereIn('external_status', $open)
            ->whereNotIn('external_order_sn', array_keys($listed) ?: [''])->pluck('external_order_sn')->filter()->all();
        // Open orders synced before buyer/payment/deadline details were stored: fill them in once.
        $backfill = Order::where('marketplace_shop_id', $shop->id)->whereIn('external_status', $open)
            ->whereNull('payment_channel')->limit(500)->pluck('external_order_sn')->filter()->all();
        $count = 0;
        foreach (array_chunk(array_values(array_unique([...$refresh, ...$stale, ...$backfill])), 50) as $chunk) {
            foreach ($this->api->orderDetail($shop, $chunk) as $detail) {
                $this->upsertOrder($shop, $detail);
                $count++;
            }
        }

        return $count;
    }

    /**
     * "Pengiriman Kilat" reservations: pull the open ones Shopee lists (last 30 days), refresh local open ones it
     * no longer lists, and fetch the tracking number once a booking is arranged. @return int bookings stored
     */
    public function bookings(MarketplaceShop $shop): int
    {
        // Open reservations (30 days), shipped ones and those matched to a buyer order (60 days: parcels on the way).
        $windows = ['READY_TO_SHIP' => 30, 'PROCESSED' => 30, 'SHIPPED' => 60, 'MATCHED' => 60];
        $listed = []; // booking_sn => status Shopee reports
        foreach ($windows as $status => $days) {
            for ($end = now(), $from = $end->copy()->subDays($days); $from < $end; $from->addDays(15)) {
                $cursor = '';
                do {
                    $page = $this->api->bookingList($shop, $from->timestamp, $from->copy()->addDays(15)->min($end)->timestamp, $cursor, 'create_time', $status);
                    foreach ($page['booking_list'] ?? [] as $bk) {
                        $listed[$bk['booking_sn']] = $bk['booking_status'] ?? $status;
                    }
                    $cursor = $page['next_cursor'] ?? '';
                } while (! empty($page['more']));
            }
        }
        $local = \App\Models\ShopeeBooking::where('marketplace_shop_id', $shop->id)->pluck('booking_status', 'booking_sn');
        // New or changed bookings, plus local ones in a listed status that Shopee no longer lists (moved on).
        $refresh = collect($listed)->filter(fn ($st, $sn) => ($local[$sn] ?? null) !== $st)->keys()->all();
        $stale = $local->filter(fn ($st, $sn) => isset($windows[$st]) && ! isset($listed[$sn]))->keys()->all();
        $count = 0;
        foreach (array_chunk(array_values(array_unique([...$refresh, ...$stale])), 50) as $chunk) {
            foreach ($this->api->bookingDetail($shop, $chunk) as $d) {
                $this->upsertBooking($shop, $d);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Return / refund requests (Seller Centre → Pengembalian/Pembatalan), last 60 days.
     * @return int requests stored
     */
    public function returns(MarketplaceShop $shop, int $days = 60): int
    {
        $count = 0;
        for ($end = now(), $from = $end->copy()->subDays($days); $from < $end; $from->addDays(15)) {
            $pageNo = 0;
            do {
                $res = $this->api->returnList($shop, $from->timestamp, $from->copy()->addDays(15)->min($end)->timestamp, $pageNo);
                foreach ($res['return'] ?? [] as $r) {
                    $this->upsertReturn($shop, $r);
                    $count++;
                }
                $pageNo++;
            } while (! empty($res['more']) && $pageNo < 50);
        }

        return $count;
    }

    public function upsertReturn(MarketplaceShop $shop, array $r): \App\Models\ShopeeReturn
    {
        $due = $r['return_seller_due_date'] ?? $r['due_date'] ?? null;

        return \App\Models\ShopeeReturn::updateOrCreate(
            ['marketplace_shop_id' => $shop->id, 'return_sn' => (string) $r['return_sn']],
            [
                'user_id' => $shop->user_id,
                'order_sn' => $r['order_sn'] ?? null,
                'status' => $r['status'] ?? 'REQUESTED',
                'reason' => $r['reason'] ?? null,
                'text_reason' => mb_substr((string) ($r['text_reason'] ?? ''), 0, 500) ?: null,
                'refund_amount' => (int) round($r['refund_amount'] ?? 0),
                'buyer_username' => $r['user']['username'] ?? null,
                'tracking_number' => ($r['tracking_number'] ?? '') ?: null,
                'items' => collect($r['item'] ?? [])->map(fn ($i) => [
                    'name' => $i['name'] ?? '', 'variant' => ($i['variation_name'] ?? '') ?: null,
                    'sku' => ($i['variation_sku'] ?? '') ?: (($i['item_sku'] ?? '') ?: null), 'qty' => (int) ($i['amount'] ?? 1),
                    'image' => $i['images'][0] ?? null,
                ])->values()->all(),
                'due_at' => $due ? Carbon::createFromTimestamp($due) : null,
                'requested_at' => ! empty($r['create_time']) ? Carbon::createFromTimestamp($r['create_time']) : null,
            ],
        );
    }

    public function upsertBooking(MarketplaceShop $shop, array $d): \App\Models\ShopeeBooking
    {
        $booking = \App\Models\ShopeeBooking::firstOrNew(['marketplace_shop_id' => $shop->id, 'booking_sn' => $d['booking_sn']]);
        $booking->fill([
            'user_id' => $shop->user_id,
            'order_sn' => ($d['order_sn'] ?? '') ?: null,
            'booking_status' => $d['booking_status'] ?? 'READY_TO_SHIP',
            'shipping_carrier' => $d['shipping_carrier'] ?? $booking->shipping_carrier,
            'items' => collect($d['item_list'] ?? [])->map(fn ($i) => [
                'name' => $i['item_name'] ?? '', 'variant' => ($i['model_name'] ?? '') ?: null,
                'sku' => ($i['model_sku'] ?? '') ?: (($i['item_sku'] ?? '') ?: null), 'qty' => (int) ($i['model_quantity'] ?? $i['model_quantity_purchased'] ?? 1),
            ])->values()->all() ?: $booking->items,
            'booked_at' => ! empty($d['create_time']) ? Carbon::createFromTimestamp($d['create_time']) : $booking->booked_at,
        ]);
        if (! $booking->tracking_number && $booking->booking_status !== 'READY_TO_SHIP' && $booking->booking_status !== 'CANCELLED') {
            try {
                $booking->tracking_number = $this->api->bookingTrackingNumber($shop, $booking->booking_sn) ?: null;
            } catch (\Throwable) {
            }
        }
        $booking->save();

        return $booking;
    }

    /** Everything after connecting a shop: profile, listings, orders and ad spend. Never throws. */
    public function initial(MarketplaceShop $shop): void
    {
        @set_time_limit(0);
        foreach (['shopInfo', 'products', 'orders', 'ads'] as $step) {
            try {
                $this->{$step}($shop);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Shopee initial sync {$shop->id} {$step}: {$e->getMessage()}");
                if ($step !== 'ads') {
                    $shop->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
                }
            }
        }
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

    /**
     * Order codes are unique across Pesanin. The same Shopee shop can be connected to more than one account
     * (e.g. reconnected under another login), so a code already taken gets the shop record's id appended.
     */
    public static function orderCode(MarketplaceShop $shop, string $orderSn): string
    {
        $code = 'SP'.$orderSn;

        return Order::where('code', $code)->exists() ? $code.'-'.$shop->id : $code;
    }

    public function upsertOrder(MarketplaceShop $shop, array $d): Order
    {
        $listings = MarketplaceListing::where('marketplace_shop_id', $shop->id)->get()->keyBy(fn ($l) => $l->item_id.':'.$l->model_id);
        $items = collect($d['item_list'] ?? [])->map(fn ($i) => [
            'item_id' => $i['item_id'], 'model_id' => $i['model_id'] ?? 0,
            'name' => $i['item_name'], 'variant' => $i['model_name'] ?? null, 'sku' => ($i['model_sku'] ?? '') ?: (($i['item_sku'] ?? '') ?: null),
            'item_sku' => ($i['item_sku'] ?? '') ?: null, 'model_sku' => ($i['model_sku'] ?? '') ?: null,
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

        $build = function () use ($shop, $d, $items, $addr, $subtotal, $status, $shipping) {
            $order = Order::firstOrNew(['marketplace_shop_id' => $shop->id, 'external_order_sn' => $d['order_sn']]);
            $order->user_id = $shop->user_id;
            $order->fill([
                'code' => $order->code ?? self::orderCode($shop, $d['order_sn']),
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
                // When the order is completed, Shopee's update_time is (close to) the completion time; used by the
                // "Selesai" tab to show orders completed today.
                'completed_at' => ($d['order_status'] ?? '') === 'COMPLETED'
                    ? (! empty($d['update_time']) ? Carbon::createFromTimestamp($d['update_time']) : ($order->completed_at ?? now()))
                    : $order->completed_at,
                // Shown like Seller Centre: buyer's payment method and the shipping option they chose at checkout.
                'payment_channel' => ($d['payment_method'] ?? '') ?: ($order->payment_channel ?? ''), // '' = asked, none given (no re-backfill)
                'courier_service' => ($d['checkout_shipping_carrier'] ?? '') ?: $order->courier_service,
                'custom_fields' => array_filter([
                    'buyer_username' => $d['buyer_username'] ?? null,
                    'ship_by' => ! empty($d['ship_by_date']) ? Carbon::createFromTimestamp($d['ship_by_date'])->toIso8601String() : null,
                    'cancel_reason' => ($d['cancel_reason'] ?? '') ?: (($d['buyer_cancel_reason'] ?? '') ?: null),
                ]) + (array) ($order->custom_fields ?? []),
            ]);
            if (! $order->exists && ! empty($d['create_time'])) {
                $order->created_at = Carbon::createFromTimestamp($d['create_time']);
            }

            return $order;
        };
        // Two syncs (background, manual, 30s poll) can insert the same order at once, and the same Shopee shop may
        // live in another account: on a unique-key clash re-read the row, or give the new row a free code.
        $order = $build();
        for ($try = 0; ; $try++) {
            try {
                $order->save();
                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($try >= 2) {
                    throw $e;
                }
                $order = $build();
                if (! $order->exists) {
                    $order->code = 'SP'.$d['order_sn'].'-'.$shop->id.($try ? '-'.Str::lower(Str::random(4)) : '');
                }
            }
        }

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
