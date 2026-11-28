<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceShop;
use Illuminate\Support\Arr;

/**
 * Promosi Toko through the Shopee Open Platform, one shape for the six kinds Seller Centre has:
 *   discount  Promo Toko (harga coret per variasi)        voucher  Voucher Toko / Produk
 *   bundle    Paket Diskon (beli banyak lebih murah)      addon    Kombo Hemat (produk tambahan murah)
 *   flash     Flash Sale Toko (slot jam dari Shopee)      top_picks Produk Unggulan (4–8 produk di halaman toko)
 * Lists are read live from Shopee (nothing stored), so what Pesanin shows is what Seller Centre shows.
 * Times are unix seconds; Shopee requires the start to be in the future.
 */
class ShopeePromotions
{
    public const TYPES = ['discount', 'voucher', 'bundle', 'addon', 'flash', 'top_picks'];

    public function __construct(private ShopeeClient $api = new ShopeeClient) {}

    private function call(MarketplaceShop $shop, string $method, string $path, array $data = []): array
    {
        return $this->api->call($shop, $method, "/api/v2/{$path}", $data)['response'] ?? [];
    }

    private static function status(?int $start, ?int $end): string
    {
        $now = time();

        return match (true) {
            ! $start => 'unknown',
            $now < $start => 'upcoming',
            ! $end || $now < $end => 'ongoing',
            default => 'expired',
        };
    }

    private static function row(int|string $id, ?string $name, ?int $start, ?int $end, string $detail, array $extra = []): array
    {
        return ['id' => (string) $id, 'name' => $name ?: "#{$id}", 'start_time' => $start, 'end_time' => $end,
            'status' => self::status($start, $end), 'detail' => $detail] + $extra;
    }

    private static function rp($n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }

    /** Every promotion of one kind (upcoming, ongoing and the latest expired ones). */
    public function list(MarketplaceShop $shop, string $type): array
    {
        $rp = fn ($n) => self::rp($n);

        return match ($type) {
            'discount' => collect($this->call($shop, 'GET', 'discount/get_discount_list', ['discount_status' => 'all', 'page_no' => 1, 'page_size' => 100])['discount_list'] ?? [])
                ->map(fn ($d) => self::row($d['discount_id'], $d['discount_name'] ?? null, $d['start_time'] ?? null, $d['end_time'] ?? null, 'Promo Toko (harga coret)'))->all(),
            'voucher' => collect($this->call($shop, 'GET', 'voucher/get_voucher_list', ['status' => 'all', 'page_no' => 1, 'page_size' => 100])['voucher_list'] ?? [])
                ->map(fn ($v) => self::row($v['voucher_id'], $v['voucher_name'] ?? null, $v['start_time'] ?? null, $v['end_time'] ?? null,
                    trim(((int) ($v['voucher_type'] ?? 1) === 2 ? 'Voucher Produk' : 'Voucher Toko').' · '.((int) ($v['reward_type'] ?? 1) === 2
                        ? 'Diskon '.($v['percentage'] ?? 0).'%'.(! empty($v['max_price']) ? " maks {$rp($v['max_price'])}" : '')
                        : 'Potongan '.$rp($v['discount_amount'] ?? 0)).(! empty($v['min_basket_price']) ? " · min. belanja {$rp($v['min_basket_price'])}" : '')),
                    ['code' => $v['voucher_code'] ?? null, 'usage' => isset($v['usage_quantity']) ? (int) ($v['current_usage'] ?? 0).'/'.(int) $v['usage_quantity'].' terpakai' : null]))->all(),
            'bundle' => collect($this->call($shop, 'GET', 'bundle_deal/get_bundle_deal_list', ['time_status' => 1, 'page_no' => 1, 'page_size' => 100])['bundle_deal_list'] ?? [])
                ->map(function ($b) use ($rp) {
                    $r = $b['bundle_deal_rule'] ?? [];
                    $min = (int) ($r['min_amount'] ?? 0);
                    $what = match ((int) ($r['rule_type'] ?? 0)) {
                        1 => "Beli {$min} harga {$rp($r['fix_price'] ?? 0)}",
                        2 => "Beli {$min} diskon ".($r['discount_percentage'] ?? 0).'%',
                        default => "Beli {$min} potong {$rp($r['discount_value'] ?? 0)}",
                    };

                    return self::row($b['bundle_deal_id'], $b['name'] ?? null, $b['start_time'] ?? null, $b['end_time'] ?? null, "Paket Diskon · {$what}");
                })->all(),
            'addon' => collect($this->call($shop, 'GET', 'add_on_deal/get_add_on_deal_list', ['promotion_status' => 'all', 'page_no' => 1, 'page_size' => 100])['add_on_deal_list'] ?? [])
                ->map(fn ($a) => self::row($a['add_on_deal_id'], $a['add_on_deal_name'] ?? null, $a['start_time'] ?? null, $a['end_time'] ?? null,
                    (int) ($a['promotion_type'] ?? 0) === 1 ? 'Hadiah dengan min. belanja' : 'Kombo Hemat (produk tambahan)'))->all(),
            'flash' => collect($this->call($shop, 'GET', 'shop_flash_sale/get_shop_flash_sale_list', ['type' => 0, 'offset' => 0, 'limit' => 100])['flash_sale_list'] ?? [])
                ->map(fn ($f) => self::row($f['flash_sale_id'], 'Flash Sale '.date('d M H:i', (int) ($f['start_time'] ?? 0) + 7 * 3600), $f['start_time'] ?? null, $f['end_time'] ?? null,
                    ((int) ($f['enabled_item_count'] ?? 0)).'/'.((int) ($f['item_count'] ?? 0)).' produk aktif', ['disabled' => (int) ($f['status'] ?? 1) === 2]))->all(),
            'top_picks' => collect($this->call($shop, 'GET', 'top_picks/get_top_picks_list')['collection_list'] ?? [])
                ->map(fn ($t) => ['id' => (string) $t['top_picks_id'], 'name' => $t['name'] ?? 'Produk Unggulan', 'start_time' => null, 'end_time' => null,
                    'status' => ! empty($t['is_activated']) ? 'ongoing' : 'inactive', 'detail' => count($t['item_list'] ?? []).' produk',
                    'items' => collect($t['item_list'] ?? [])->map(fn ($i) => ['item_id' => (int) $i['item_id'], 'name' => $i['item_name'] ?? null])->values()->all()])->all(),
        };
    }

    /** Every item_id found anywhere in a Shopee response (item lists differ per promotion type). @return int[] */
    private static function itemIds(mixed $node): array
    {
        $out = [];
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if ($k === 'item_id' && is_numeric($v)) {
                    $out[] = (int) $v;
                } elseif (in_array($k, ['item_id_list', 'item_ids'], true) && is_array($v) && array_is_list($v) && array_filter($v, 'is_numeric') === $v) {
                    array_push($out, ...array_map('intval', $v)); // e.g. product vouchers: "item_id_list": [1, 2]
                } elseif (is_array($v)) {
                    array_push($out, ...self::itemIds($v));
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Cek promo aktif per produk: which ongoing or upcoming promotions each item is already in, so a new discount
     * does not stack on top of a flash sale, bundle or voucher without the seller noticing.
     * Uses the promotion modules Pesanin already manages (list + the item list of each promotion). Each type is read
     * on its own: one Shopee error only drops that type (reported in `errors`). Cached 15 minutes per shop.
     *
     * @return array{items: array<int, array<int, array{type: string, label: string, name: string, status: string, start_time: ?int, end_time: ?int, detail: string}>>, shop_wide: array, errors: array<int, string>}
     */
    public function activeItems(MarketplaceShop $shop, bool $fresh = false): array
    {
        $key = "shopee:active_promos:{$shop->id}";
        if (! $fresh && ($hit = \Illuminate\Support\Facades\Cache::get($key))) {
            return $hit;
        }
        $items = [];
        $shopWide = [];
        $errors = [];
        $live = fn (array $r) => in_array($r['status'], ['ongoing', 'upcoming'], true);
        $add = function (array $ids, string $type, string $label, array $row) use (&$items) {
            foreach ($ids as $id) {
                $items[$id][] = ['type' => $type, 'label' => $label, 'name' => $row['name'], 'status' => $row['status'],
                    'start_time' => $row['start_time'], 'end_time' => $row['end_time'], 'detail' => $row['detail']];
            }
        };
        $each = function (string $type, string $label, callable $fn) use (&$errors) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $errors[] = "{$label}: ".mb_substr($e->getMessage(), 0, 120);
            }
        };

        $each('discount', 'Promo Toko', function () use ($shop, $live, $add) {
            foreach (array_filter($this->list($shop, 'discount'), $live) as $d) {
                $r = $this->call($shop, 'GET', 'discount/get_discount', ['discount_id' => (int) $d['id'], 'page_no' => 1, 'page_size' => 100]);
                $add(self::itemIds($r['item_list'] ?? $r), 'discount', 'Promo Toko', $d);
            }
        });
        $each('flash', 'Flash Sale', function () use ($shop, $live, $add) {
            foreach (array_filter($this->list($shop, 'flash'), fn ($f) => $live($f) && empty($f['disabled'])) as $f) {
                $r = $this->call($shop, 'GET', 'shop_flash_sale/get_shop_flash_sale_items', ['flash_sale_id' => (int) $f['id'], 'offset' => 0, 'limit' => 100]);
                $add(self::itemIds($r), 'flash', 'Flash Sale', $f);
            }
        });
        $each('bundle', 'Paket Diskon', function () use ($shop, $live, $add) {
            foreach (array_filter($this->list($shop, 'bundle'), $live) as $b) {
                $r = $this->call($shop, 'GET', 'bundle_deal/get_bundle_deal_item', ['bundle_deal_id' => (int) $b['id']]);
                $add(self::itemIds($r), 'bundle', 'Paket Diskon', $b);
            }
        });
        $each('addon', 'Kombo Hemat', function () use ($shop, $live, $add) {
            foreach (array_filter($this->list($shop, 'addon'), $live) as $a) {
                $r = $this->call($shop, 'GET', 'add_on_deal/get_add_on_deal_main_item', ['add_on_deal_id' => (int) $a['id']]);
                $add(self::itemIds($r), 'addon', 'Kombo Hemat', $a);
            }
        });
        $each('voucher', 'Voucher', function () use ($shop, $live, $add, &$shopWide) {
            $raw = collect($this->call($shop, 'GET', 'voucher/get_voucher_list', ['status' => 'all', 'page_no' => 1, 'page_size' => 100])['voucher_list'] ?? [])->keyBy('voucher_id');
            foreach (array_filter($this->list($shop, 'voucher'), $live) as $v) {
                if ((int) ($raw[$v['id']]['voucher_type'] ?? 1) === 2) {
                    $r = $this->call($shop, 'GET', 'voucher/get_voucher', ['voucher_id' => (int) $v['id']]);
                    $add(self::itemIds($r), 'voucher', 'Voucher Produk', $v);
                } else {
                    // Shop voucher: applies to every product.
                    $shopWide[] = ['type' => 'voucher', 'label' => 'Voucher Toko', 'name' => $v['name'], 'status' => $v['status'],
                        'start_time' => $v['start_time'], 'end_time' => $v['end_time'], 'detail' => $v['detail']];
                }
            }
        });

        $out = ['items' => $items, 'shop_wide' => $shopWide, 'errors' => $errors, 'checked_at' => now()->toIso8601String()];
        \Illuminate\Support\Facades\Cache::put($key, $out, now()->addMinutes(15));

        return $out;
    }

    /** Free flash-sale time slots Shopee offers for a period (unix seconds). */
    public function timeSlots(MarketplaceShop $shop, int $from, int $to): array
    {
        $res = $this->api->call($shop, 'GET', '/api/v2/shop_flash_sale/get_time_slot_id', ['start_time' => $from, 'end_time' => $to])['response'] ?? [];

        return collect(Arr::isList($res) ? $res : ($res['time_slot_list'] ?? $res['list'] ?? []))
            ->map(fn ($s) => ['id' => (int) $s['timeslot_id'], 'start_time' => (int) $s['start_time'], 'end_time' => (int) $s['end_time']])->values()->all();
    }

    /**
     * Create a promotion and attach its products. Creation is several Shopee calls: when the promotion is made but
     * some products are rejected, it still exists and the rejected ones come back in "warnings".
     * @return array{id: string, warnings: string[]}
     */
    public function create(MarketplaceShop $shop, string $type, array $d): array
    {
        $warn = [];
        $collect = function (array $res, string $key = 'error_list') use (&$warn) {
            foreach ((array) ($res[$key] ?? $res['failed_list'] ?? $res['failure_list'] ?? []) as $e) {
                $warn[] = 'Produk '.($e['item_id'] ?? '?').(! empty($e['model_id']) ? "/{$e['model_id']}" : '').': '.($e['fail_message'] ?? $e['fail_error'] ?? $e['failed_reason'] ?? $e['err_msg'] ?? 'ditolak Shopee');
            }
        };
        // Products as [{item_id, models: [{model_id, price, stock?}], purchase_limit?}] (model_id 0 = no variations).
        $items = collect($d['items'] ?? []);

        switch ($type) {
            case 'discount':
                $id = $this->call($shop, 'POST', 'discount/add_discount', ['discount_name' => $d['name'], 'start_time' => $d['start_time'], 'end_time' => $d['end_time']])['discount_id'];
                $collect($this->call($shop, 'POST', 'discount/add_discount_item', ['discount_id' => (int) $id, 'item_list' => $items->map(fn ($i) => self::hasModels($i)
                    ? ['item_id' => (int) $i['item_id'], 'purchase_limit' => (int) ($i['purchase_limit'] ?? 0), 'model_list' => collect($i['models'])->map(fn ($m) => ['model_id' => (int) $m['model_id'], 'model_promotion_price' => (float) $m['price']])->values()->all()]
                    : ['item_id' => (int) $i['item_id'], 'purchase_limit' => (int) ($i['purchase_limit'] ?? 0), 'item_promotion_price' => (float) $i['models'][0]['price']])->values()->all()]));
                break;

            case 'voucher':
                $body = ['voucher_name' => $d['name'], 'voucher_code' => strtoupper($d['code']), 'start_time' => $d['start_time'], 'end_time' => $d['end_time'],
                    'voucher_type' => $d['voucher_type'] === 'product' ? 2 : 1, 'reward_type' => $d['reward_type'] === 'percentage' ? 2 : 1,
                    'usage_quantity' => (int) $d['usage_quantity'], 'min_basket_price' => (float) ($d['min_basket_price'] ?? 0), 'display_channel_list' => [1]];
                $body += $d['reward_type'] === 'percentage'
                    ? ['percentage' => (int) $d['percentage']] + (! empty($d['max_price']) ? ['max_price' => (float) $d['max_price']] : [])
                    : ['discount_amount' => (float) $d['discount_amount']];
                if ($d['voucher_type'] === 'product') {
                    $body['item_id_list'] = $items->pluck('item_id')->map(fn ($i) => (int) $i)->values()->all();
                }
                $id = $this->call($shop, 'POST', 'voucher/add_voucher', $body)['voucher_id'];
                break;

            case 'bundle':
                $rule = match ($d['rule_type']) {
                    'fix_price' => ['rule_type' => 1, 'fix_price' => (float) $d['value']],
                    'percentage' => ['rule_type' => 2, 'discount_percentage' => (float) $d['value']],
                    default => ['rule_type' => 3, 'discount_value' => (float) $d['value']],
                };
                $id = $this->call($shop, 'POST', 'bundle_deal/add_bundle_deal', $rule + ['name' => $d['name'], 'start_time' => $d['start_time'], 'end_time' => $d['end_time'],
                    'min_amount' => (int) $d['min_amount'], 'purchase_limit' => (int) ($d['purchase_limit'] ?? 0)])['bundle_deal_id'];
                $collect($this->call($shop, 'POST', 'bundle_deal/add_bundle_deal_item', ['bundle_deal_id' => (int) $id,
                    'item_list' => $items->map(fn ($i) => ['item_id' => (int) $i['item_id'], 'status' => 1])->values()->all()]), 'failed_list');
                break;

            case 'addon':
                $id = $this->call($shop, 'POST', 'add_on_deal/add_add_on_deal', ['add_on_deal_name' => $d['name'], 'start_time' => $d['start_time'], 'end_time' => $d['end_time'],
                    'promotion_type' => 0, 'promotion_purchase_limit' => (int) ($d['purchase_limit'] ?? 1)])['add_on_deal_id'];
                $collect($this->call($shop, 'POST', 'add_on_deal/add_add_on_deal_main_item', ['add_on_deal_id' => (int) $id,
                    'main_item_list' => collect($d['main_items'] ?? [])->map(fn ($i) => ['item_id' => (int) $i['item_id'], 'status' => 1])->values()->all()]), 'failed_list');
                $collect($this->call($shop, 'POST', 'add_on_deal/add_add_on_deal_sub_item', ['add_on_deal_id' => (int) $id,
                    'sub_item_list' => $items->flatMap(fn ($i) => collect($i['models'])->map(fn ($m) => ['item_id' => (int) $i['item_id'], 'model_id' => (int) $m['model_id'],
                        'status' => 1, 'sub_item_input_price' => (float) $m['price'], 'sub_item_limit' => (int) ($i['purchase_limit'] ?? 1)]))->values()->all()]), 'failed_list');
                break;

            case 'flash':
                $id = $this->call($shop, 'POST', 'shop_flash_sale/create_shop_flash_sale', ['timeslot_id' => (int) $d['timeslot_id']])['flash_sale_id'];
                $collect($this->call($shop, 'POST', 'shop_flash_sale/add_shop_flash_sale_items', ['flash_sale_id' => (int) $id, 'items' => $items->map(fn ($i) => self::hasModels($i)
                    ? ['item_id' => (int) $i['item_id'], 'purchase_limit' => (int) ($i['purchase_limit'] ?? 0),
                        'models' => collect($i['models'])->map(fn ($m) => ['model_id' => (int) $m['model_id'], 'input_promo_price' => (float) $m['price'], 'stock' => (int) ($m['stock'] ?? 1)])->values()->all()]
                    : ['item_id' => (int) $i['item_id'], 'purchase_limit' => (int) ($i['purchase_limit'] ?? 0),
                        'item_input_promo_price' => (float) $i['models'][0]['price'], 'item_stock' => (int) ($i['models'][0]['stock'] ?? 1)])->values()->all()]), 'failed_items');
                break;

            case 'top_picks':
                $id = $this->call($shop, 'POST', 'top_picks/add_top_picks', ['name' => $d['name'],
                    'item_id_list' => $items->pluck('item_id')->map(fn ($i) => (int) $i)->values()->all(), 'is_activated' => (bool) ($d['activate'] ?? true)])['top_picks_id'] ?? null;
                break;
        }

        return ['id' => (string) ($id ?? ''), 'warnings' => $warn];
    }

    private static function hasModels(array $item): bool
    {
        return collect($item['models'] ?? [])->contains(fn ($m) => (int) ($m['model_id'] ?? 0) > 0);
    }

    /** Stop a running promotion now (ongoing), or remove one that has not started (upcoming). */
    public function stop(MarketplaceShop $shop, string $type, string $id, string $status): void
    {
        $remove = $status === 'upcoming' || $status === 'inactive';
        [$path, $key] = match ($type) {
            'discount' => [$remove ? 'discount/delete_discount' : 'discount/end_discount', 'discount_id'],
            'voucher' => [$remove ? 'voucher/delete_voucher' : 'voucher/end_voucher', 'voucher_id'],
            'bundle' => [$remove ? 'bundle_deal/delete_bundle_deal' : 'bundle_deal/end_bundle_deal', 'bundle_deal_id'],
            'addon' => [$remove ? 'add_on_deal/delete_add_on_deal' : 'add_on_deal/end_add_on_deal', 'add_on_deal_id'],
            'flash' => [$remove ? 'shop_flash_sale/delete_shop_flash_sale' : 'shop_flash_sale/update_shop_flash_sale', 'flash_sale_id'],
            'top_picks' => ['top_picks/delete_top_picks', 'top_picks_id'],
        };
        $body = [$key => (int) $id] + ($type === 'flash' && ! $remove ? ['status' => 2] : []);
        $this->call($shop, 'POST', $path, $body);
    }

    /** Produk Unggulan: switch one collection on (Shopee shows one active collection) or off. */
    public function toggleTopPicks(MarketplaceShop $shop, string $id, bool $on): void
    {
        $this->call($shop, 'POST', 'top_picks/update_top_picks', ['top_picks_id' => (int) $id, 'is_activated' => $on]);
    }
}
