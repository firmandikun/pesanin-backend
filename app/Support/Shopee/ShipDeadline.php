<?php

namespace App\Support\Shopee;

use App\Models\Order;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Telegram\TelegramClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Batas kirim: Shopee counts an order as late (penalty points, "Pesanan Terlambat Dikirim") when the courier has
 * not received it by the ship-by date. Warns BEFORE that happens: orders not yet handed to the courier whose
 * deadline is within 6 hours, and again once the deadline has passed.
 */
class ShipDeadline
{
    /** Not with the courier yet. */
    public const OPEN = ['READY_TO_SHIP' => 'Belum atur pengiriman', 'RETRY_SHIP' => 'Kirim ulang', 'PROCESSED' => 'Sudah atur, belum diserahkan ke kurir'];

    public const SOON_HOURS = 6;

    /** Open orders with a deadline in the next 24 hours (or already passed), most urgent first. */
    public static function due(User $user, ?int $shopId = null): array
    {
        $now = now();

        return Order::where('user_id', $user->id)->where('channel', 'shopee')->whereIn('external_status', array_keys(self::OPEN))
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))->where('created_at', '>=', $now->copy()->subDays(30))
            ->with('marketplaceShop:id,shop_name')->limit(1000)->get()
            ->map(function (Order $o) use ($now) {
                $by = $o->custom_fields['ship_by'] ?? null;
                if (! $by) {
                    return null;
                }
                $at = Carbon::parse($by);
                $hours = round($now->diffInMinutes($at, false) / 60, 1);

                return $hours > 24 ? null : [
                    'order_id' => $o->id, 'order_sn' => $o->external_order_sn, 'shop' => $o->marketplaceShop?->shop_name,
                    'ship_by' => $at->toIso8601String(), 'hours_left' => $hours, 'late' => $hours <= 0,
                    'status' => self::OPEN[$o->external_status] ?? $o->external_status, 'resi' => $o->tracking_number,
                ];
            })->filter()->sortBy('hours_left')->values()->all();
    }

    /** Every 30 minutes: warn once per order when it gets within 6 hours, and once more when it is late. */
    public static function notify(User $user): int
    {
        $lines = [];
        foreach (self::due($user) as $d) {
            $stage = $d['late'] ? 'late' : ($d['hours_left'] <= self::SOON_HOURS ? 'soon' : null);
            if (! $stage || ! Cache::add("ship_deadline:{$d['order_id']}:{$stage}", 1, now()->addDays(3))) {
                continue;
            }
            $lines[] = '• '.($stage === 'late' ? 'TERLAMBAT ' : 'sisa '.self::left($d['hours_left']).' ').$d['order_sn'].($d['shop'] ? " ({$d['shop']})" : '').' · '.$d['status'];
        }
        if (! $lines) {
            return 0;
        }
        $late = count(array_filter($lines, fn ($l) => str_contains($l, 'TERLAMBAT')));
        $title = $late ? "{$late} pesanan lewat batas kirim Shopee" : count($lines).' pesanan hampir lewat batas kirim';
        $body = implode("\n", array_slice($lines, 0, 15)).(count($lines) > 15 ? "\n+".(count($lines) - 15).' lainnya' : '');
        Notifier::send($user->id, 'ship_deadline', $title, $body, '/marketplace/health');
        try {
            $i = $user->integration('telegram');
            if ($i && $i->key('bot_token') && ! empty($i->settings['chat_id'])) {
                (new TelegramClient($i->key('bot_token')))->sendMessage($i->settings['chat_id'], "⏰ {$title}\n{$body}");
            }
        } catch (Throwable) {
            // in-app notification already sent
        }

        return count($lines);
    }

    public static function left(float $hours): string
    {
        return $hours >= 1 ? floor($hours).' jam' : max(1, (int) round($hours * 60)).' menit';
    }
}
