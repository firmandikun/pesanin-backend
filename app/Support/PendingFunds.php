<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ShopeeReturn;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Saldo → Rincian Dana Pending: Shopee holds an order's income until it is completed. This splits that money by
 * where the order is (not shipped yet, on the way, delivered waiting for the buyer, cancel request, return) and
 * flags orders that hold money too long, from Pesanin's own synced orders.
 */
class PendingFunds
{
    public const GROUPS = [
        'cancel' => ['Ada pengajuan pembatalan', 'Dana bisa batal kalau pembatalan disetujui. Setujui atau tolak di detail pesanan.', ['IN_CANCEL']],
        'return' => ['Retur / pengembalian', 'Pembeli mengajukan pengembalian: dana tertahan sampai retur selesai. Tanggapi sebelum batas waktu.', ['TO_RETURN']],
        'delivered' => ['Sudah sampai, menunggu konfirmasi pembeli', 'Paket sudah diterima, dana cair setelah pembeli menekan "Pesanan Diterima" atau otomatis selesai.', ['TO_CONFIRM_RECEIVE']],
        'shipping' => ['Dalam pengiriman', 'Paket sedang di kurir.', ['SHIPPED']],
        'not_shipped' => ['Belum dikirim', 'Dana baru berjalan setelah paket dikirim. Kirim sebelum batas kirim.', ['PENDING', 'READY_TO_SHIP', 'PROCESSED', 'RETRY_SHIP']],
    ];

    public static function breakdown(User $user, ?int $shopId = null): array
    {
        $statuses = collect(self::GROUPS)->pluck(2)->flatten()->all();
        $orders = Order::where('user_id', $user->id)->where('channel', 'shopee')->whereIn('external_status', $statuses)
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->where('created_at', '>=', now()->subDays(90))->with('marketplaceShop:id,shop_name,is_demo')
            ->get(['id', 'marketplace_shop_id', 'external_order_sn', 'external_status', 'subtotal', 'escrow_amount', 'customer_name', 'custom_fields', 'tracking_number', 'shipped_at', 'created_at', 'updated_at'])
            ->reject(fn ($o) => $o->marketplaceShop?->is_demo);
        // Orders of an open return request count as "return" whatever their order status says.
        $returnSns = ShopeeReturn::where('user_id', $user->id)->whereIn('status', ShopeeReturn::NEEDS_SELLER)->pluck('order_sn')->flip();
        $now = now();

        $groups = collect(self::GROUPS)->map(fn ($g, $k) => ['key' => $k, 'title' => $g[0], 'help' => $g[1], 'count' => 0, 'amount' => 0, 'stuck' => 0, 'items' => []])->all();
        foreach ($orders as $o) {
            $key = isset($returnSns[$o->external_order_sn]) ? 'return'
                : collect(self::GROUPS)->search(fn ($g) => in_array($o->external_status, $g[2], true));
            if (! $key) {
                continue;
            }
            $amount = (int) ($o->escrow_amount ?? $o->subtotal);
            [$since, $stuck] = self::age($o, $key, $now);
            $groups[$key]['count']++;
            $groups[$key]['amount'] += $amount;
            $groups[$key]['stuck'] += $stuck ? 1 : 0;
            $groups[$key]['items'][] = [
                'order_id' => $o->id, 'order_sn' => $o->external_order_sn, 'shop' => $o->marketplaceShop?->shop_name,
                'buyer' => $o->custom_fields['buyer_username'] ?? $o->customer_name, 'amount' => $amount, 'estimated' => $o->escrow_amount === null,
                'since' => $since?->toIso8601String(), 'days' => $since ? (int) $since->diffInDays($now, true) : null, 'stuck' => $stuck,
                'resi' => $o->tracking_number,
            ];
        }
        foreach ($groups as &$g) {
            // Stuck first, then the oldest.
            $g['items'] = collect($g['items'])->sortBy([['stuck', 'desc'], ['days', 'desc']])->take(100)->values()->all();
        }

        return [
            'groups' => array_values($groups),
            'total' => array_sum(array_column($groups, 'amount')),
            'count' => array_sum(array_column($groups, 'count')),
            'stuck' => array_sum(array_column($groups, 'stuck')),
        ];
    }

    /** Since when the order is in its current step, and whether it holds the money too long. @return array{0: ?Carbon, 1: string|null} */
    private static function age(Order $o, string $key, Carbon $now): array
    {
        return match ($key) {
            // On the way for more than 10 days: likely lost or stuck at the courier.
            'shipping' => [$s = $o->shipped_at ?? $o->updated_at, $s && $s->diffInDays($now, true) > 10 ? 'Lebih dari 10 hari di kurir, cek resi / klaim paket hilang' : null],
            // Delivered for more than 5 days but not completed: ask the buyer to confirm.
            'delivered' => [$s = $o->updated_at, $s && $s->diffInDays($now, true) > 5 ? 'Sudah sampai lebih dari 5 hari, minta pembeli menekan Pesanan Diterima' : null],
            'not_shipped' => [$o->created_at, ($by = $o->custom_fields['ship_by'] ?? null) && Carbon::parse($by)->isPast() ? 'Lewat batas kirim' : null],
            'cancel' => [$o->updated_at, 'Putuskan pembatalan'],
            'return' => [$o->updated_at, 'Tanggapi retur'],
            default => [$o->updated_at, null],
        };
    }
}
