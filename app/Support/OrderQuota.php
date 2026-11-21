<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Monthly order quota per plan (Gratis 100, Starter 1.000, Bisnis 10.000, Pro unlimited). Orders keep coming in so
 * buyers are never lost, but an order placed over the quota has its buyer details locked until the seller upgrades
 * to a plan whose quota covers that month. The seller is warned at 80% and at 100%.
 */
class OrderQuota
{
    public static function limit(User $user): ?int
    {
        return Plans::forUser($user)['limits']['orders'] ?? null;
    }

    /** Orders counted for the month of $at (web/manual and Shopee alike, abandoned checkouts excluded). */
    public static function used(User $user, $at = null): int
    {
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now();

        return $user->orders()->where('is_abandoned', false)->whereBetween('created_at', [$at->copy()->startOfMonth(), $at->copy()->endOfMonth()])->count();
    }

    /** Whether the next order this month is over the quota. */
    public static function isOver(User $user): bool
    {
        $limit = self::limit($user);

        return $limit !== null && self::used($user) >= $limit;
    }

    /**
     * Locked = placed over quota and the seller's current plan still doesn't cover that month's orders.
     * Upgrading unlocks automatically.
     */
    public static function locked(Order $order, User $user): bool
    {
        if (! $order->over_quota) {
            return false;
        }
        $limit = self::limit($user);

        return $limit !== null && Cache::remember("quota:used:{$user->id}:".$order->created_at?->format('Y-m'), 60, fn () => self::used($user, $order->created_at)) > $limit;
    }

    /** Hide buyer details of a locked order (keeps what the seller needs to see it exists). */
    public static function mask(Order $order, User $user): Order
    {
        if (! self::locked($order, $user)) {
            return $order->setAttribute('locked', false);
        }
        foreach (['customer_name' => 'Pembeli (terkunci)', 'phone' => null, 'email' => null, 'address' => null, 'village' => null, 'postal_code' => null, 'notes' => null, 'custom_fields' => null] as $k => $v) {
            $order->setAttribute($k, $v);
        }

        return $order->setAttribute('locked', true);
    }

    /** Warn the seller once a month at 80% and at 100% of the quota. */
    public static function warn(User $user): void
    {
        $limit = self::limit($user);
        if ($limit === null) {
            return;
        }
        $used = self::used($user);
        foreach ([100 => 'Kuota pesanan bulan ini sudah habis', 80 => 'Kuota pesanan bulan ini hampir habis'] as $pct => $title) {
            if ($used >= (int) ceil($limit * $pct / 100)) {
                if (Cache::add("quota:warned:{$user->id}:".now()->format('Y-m').":{$pct}", 1, now()->endOfMonth())) {
                    Notifier::send($user->id, 'billing', $title, $pct === 100
                        ? "Sudah {$used} dari {$limit} pesanan. Pesanan baru tetap masuk, tapi data pembelinya terkunci sampai kamu upgrade paket."
                        : "Sudah {$used} dari {$limit} pesanan bulan ini. Upgrade paket agar data pembeli pesanan berikutnya tetap terbuka.", '/settings/billing');
                }

                return;
            }
        }
    }
}
