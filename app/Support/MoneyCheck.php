<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ShopeeReturn;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cek Dana: money a Shopee seller may be missing, from the income breakdown Shopee gives per order (last 90 days).
 *  - income not final / not in yet long after the order completed;
 *  - shipping charged to the seller (courier weight ≠ listed weight, wrong courier rate): claimable;
 *  - return / RTS shipping charged: check whether it was the buyer's fault;
 *  - Shopee fees far above the shop's usual rate;
 *  - parcels shipped long ago that never arrived (possibly lost);
 *  - returns waiting for the seller (refund goes to the buyer when ignored).
 * Each finding links to the order so the seller can check it and claim in Seller Centre.
 */
class MoneyCheck
{
    public const DAYS = 90;

    public const KINDS = [
        'income_pending' => ['Penghasilan belum final', 'Pesanan sudah selesai lebih dari 3 hari tapi penghasilannya belum final dari Shopee. Cek di Seller Centre → Keuangan → Penghasilan Saya; kalau tidak masuk, ajukan ke Shopee.'],
        'shipping_charged' => ['Ongkir dibebankan ke penjual', 'Ongkir aktual lebih besar dari ongkir yang dibayar pembeli + subsidi, jadi selisihnya memotong penghasilan. Biasanya berat di kurir beda dengan berat produk. Cek berat produk; bila kurir salah timbang, ajukan klaim selisih ongkir.'],
        'return_shipping' => ['Ongkir retur / pengembalian dipotong', 'Penghasilan dipotong ongkir retur atau ongkir pengembalian paket. Kalau retur bukan karena kesalahan toko (pembeli berubah pikiran, alamat salah), ajukan banding ke Shopee.'],
        'high_fee' => ['Potongan biaya tidak wajar', 'Persentase potongan Shopee pesanan ini jauh di atas rata-rata toko. Cek rincian biaya (kampanye, AMS, layanan) di detail pesanan.'],
        'lost' => ['Paket lama tidak sampai', 'Paket sudah dikirim lebih dari 14 hari tapi belum sampai / selesai. Berpotensi hilang: hubungi Shopee untuk klaim paket hilang.'],
        'return_open' => ['Retur menunggu tanggapan', 'Pengembalian dana menunggu keputusanmu. Kalau dibiarkan melewati batas waktu, dana otomatis dikembalikan ke pembeli.'],
    ];

    /** @return array{kinds: array, total: int, count: int} */
    public static function scan(User $user, ?int $shopId = null): array
    {
        $since = now()->subDays(self::DAYS);
        $base = fn () => Order::where('user_id', $user->id)->where('channel', 'shopee')->where('created_at', '>=', $since)
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))->with('marketplaceShop:id,shop_name');
        $found = array_fill_keys(array_keys(self::KINDS), []);
        $row = fn (Order $o, int $amount, string $detail) => [
            'order_id' => $o->id, 'order_sn' => $o->external_order_sn, 'shop' => $o->marketplaceShop?->shop_name,
            'date' => ($o->completed_at ?? $o->shipped_at ?? $o->created_at)?->toIso8601String(), 'amount' => $amount, 'detail' => $detail,
        ];

        // 1. Completed, but the income is still an estimate (or missing) after 3 days.
        foreach ($base()->where('external_status', 'COMPLETED')->where('completed_at', '<=', now()->subDays(3))
            ->where(fn ($w) => $w->where('escrow_final', false)->orWhereNull('escrow_amount'))->limit(200)->get() as $o) {
            $found['income_pending'][] = $row($o, (int) ($o->escrow_amount ?? $o->subtotal), $o->escrow_amount === null ? 'Penghasilan belum tercatat' : 'Masih perkiraan Shopee');
        }

        // 2-4. Read the income breakdown of orders that have one.
        $withFees = $base()->whereNotNull('fee_breakdown')->where('subtotal', '>', 0)
            ->where(fn ($w) => $w->whereNull('external_status')->orWhereNotIn('external_status', ['CANCELLED', 'IN_CANCEL', 'UNPAID']))
            ->get(['id', 'marketplace_shop_id', 'external_order_sn', 'external_status', 'subtotal', 'marketplace_fee', 'fee_breakdown', 'completed_at', 'shipped_at', 'created_at']);
        $rates = self::shopFeeRates($withFees);
        foreach ($withFees as $o) {
            $lines = collect($o->fee_breakdown)->keyBy('key')->map(fn ($l) => (int) $l['amount']);
            // Seller-paid shipping without the seller choosing to subsidise it.
            $ship = $lines->only(['actual_shipping_fee', 'shopee_shipping_rebate', 'buyer_paid_shipping_fee', 'shipping_fee_discount_from_3pl'])->sum();
            if ($lines->has('actual_shipping_fee') && $ship <= -1000 && ! $lines->has('seller_shipping_discount')) {
                $found['shipping_charged'][] = $row($o, -$ship, 'Ongkir aktual '.self::rp(-($lines['actual_shipping_fee'] ?? 0)).' · dibayar pembeli+subsidi '.self::rp(($lines['buyer_paid_shipping_fee'] ?? 0) + ($lines['shopee_shipping_rebate'] ?? 0) + ($lines['shipping_fee_discount_from_3pl'] ?? 0)));
            }
            $ret = -(($lines['reverse_shipping_fee'] ?? 0) + ($lines['final_return_to_seller_shipping_fee'] ?? 0)) - ($lines['rsf_seller_protection_fee_claim_amount'] ?? 0);
            if ($ret >= 1000) {
                $found['return_shipping'][] = $row($o, $ret, collect(['reverse_shipping_fee' => 'Ongkir retur', 'final_return_to_seller_shipping_fee' => 'Ongkir pengembalian ke penjual'])
                    ->filter(fn ($l, $k) => $lines->has($k))->map(fn ($l, $k) => $l.' '.self::rp(-$lines[$k]))->implode(' · '));
            }
            $rate = $o->marketplace_fee / $o->subtotal;
            $usual = $rates[$o->marketplace_shop_id] ?? null;
            if ($usual !== null && $o->subtotal >= 20000 && $rate > $usual + 0.08) {
                $extra = (int) round(($rate - $usual) * $o->subtotal);
                $found['high_fee'][] = $row($o, $extra, 'Potongan '.round($rate * 100, 1).'% (biasanya '.round($usual * 100, 1).'%)'
                    .($lines->has('campaign_fee') ? ' · ada biaya kampanye' : '').($lines->has('order_ams_commission_fee') ? ' · ada komisi affiliate' : ''));
            }
        }

        // 5. Shipped more than 14 days ago, still not delivered.
        foreach ($base()->whereIn('external_status', ['SHIPPED', 'RETRY_SHIP'])->where(fn ($w) => $w->where('shipped_at', '<=', now()->subDays(14))
            ->orWhere(fn ($x) => $x->whereNull('shipped_at')->where('created_at', '<=', now()->subDays(16))))->limit(200)->get() as $o) {
            $found['lost'][] = $row($o, (int) ($o->escrow_amount ?? $o->subtotal), 'Dikirim '.(int) ($o->shipped_at ?? $o->created_at)->diffInDays(now(), true).' hari lalu'.($o->tracking_number ? " · resi {$o->tracking_number}" : ''));
        }

        // 6. Returns waiting for the seller.
        $orderIds = Order::where('user_id', $user->id)->where('channel', 'shopee')->pluck('id', 'external_order_sn');
        foreach (ShopeeReturn::where('user_id', $user->id)->whereIn('status', ShopeeReturn::NEEDS_SELLER)
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))->with('marketplaceShop:id,shop_name')->limit(200)->get() as $r) {
            $found['return_open'][] = ['order_id' => $orderIds[$r->order_sn] ?? null, 'order_sn' => $r->order_sn, 'shop' => $r->marketplaceShop?->shop_name,
                'date' => $r->requested_at?->toIso8601String(), 'amount' => (int) $r->refund_amount,
                'detail' => trim(($r->reason ?? '').($r->due_at ? ' · batas '.$r->due_at->timezone('Asia/Jakarta')->format('d M H:i') : ''))];
        }

        $kinds = collect(self::KINDS)->map(function ($meta, $k) use ($found) {
            $items = collect($found[$k])->sortByDesc('amount')->values();

            return ['key' => $k, 'title' => $meta[0], 'help' => $meta[1], 'count' => $items->count(), 'amount' => (int) $items->sum('amount'), 'items' => $items->take(100)->all()];
        })->values();

        return ['kinds' => $kinds->all(), 'count' => $kinds->sum('count'),
            // Money at stake: everything except the "not final" estimates (those usually arrive by themselves).
            'total' => (int) $kinds->whereNotIn('key', ['income_pending'])->sum('amount'), 'days' => self::DAYS];
    }

    /** Usual Shopee fee rate per shop (median), from shops with enough orders. */
    private static function shopFeeRates(Collection $orders): array
    {
        return $orders->groupBy('marketplace_shop_id')->filter(fn ($g) => $g->count() >= 10)
            ->map(function ($g) {
                $r = $g->map(fn ($o) => $o->marketplace_fee / $o->subtotal)->sort()->values();

                return $r[intdiv($r->count(), 2)];
            })->all();
    }

    private static function rp(int $v): string
    {
        return 'Rp'.number_format($v, 0, ',', '.');
    }

    /** Daily: tell the seller about new findings (each order/kind once). */
    public static function notify(User $user): int
    {
        $r = self::scan($user);
        $key = "money_check:{$user->id}";
        $seen = Cache::get($key, []);
        $now = [];
        $new = [];
        foreach ($r['kinds'] as $k) {
            if ($k['key'] === 'income_pending') {
                continue; // usually arrives by itself; shown on the page only
            }
            foreach ($k['items'] as $i) {
                $id = $k['key'].':'.($i['order_sn'] ?? $i['order_id']);
                $now[] = $id;
                if (! in_array($id, $seen, true)) {
                    $new[$k['title']] = ($new[$k['title']] ?? ['n' => 0, 'amount' => 0]);
                    $new[$k['title']]['n']++;
                    $new[$k['title']]['amount'] += $i['amount'];
                }
            }
        }
        Cache::put($key, $now, now()->addDays(120));
        if (! $new) {
            return 0;
        }
        $lines = collect($new)->map(fn ($v, $t) => "• {$t}: {$v['n']} pesanan (".self::rp($v['amount']).')')->implode("\n");
        Notifier::send($user->id, 'money', 'Ada dana yang perlu dicek / diklaim', $lines, '/marketplace/money-check');

        return array_sum(array_column($new, 'n'));
    }
}
