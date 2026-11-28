<?php

namespace App\Support;

use App\Models\ShopeeBooking;
use App\Models\User;
use App\Support\Telegram\TelegramClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Scheduled "cetak resi": at the seller's chosen times (WIB) every Shopee air waybill that is ready and not yet
 * printed (orders + Pengiriman Kilat) is merged into one PDF, post-processed like the manual print (product names
 * hidden, "# | SKU | Qty"), and sent to the seller's Telegram. Each label is sent once (awb_printed_at).
 */
class AwbSchedule
{
    public const MAX = 200;

    /** Run every minute: send for each seller whose time slot fell within the last 15 minutes and wasn't done yet. */
    public static function run(?CarbonInterface $now = null): int
    {
        $now = ($now ?? now())->copy()->setTimezone(Dates::TZ);
        $sent = 0;
        $users = User::whereHas('integrations', fn ($q) => $q->where('provider', 'telegram')->where('status', 'active'))->get();
        foreach ($users as $user) {
            $s = OrderAutomation::settings($user);
            if (! $s['awb_schedule']) {
                continue;
            }
            foreach (self::slots($s) as ['time' => $hm, 'scope' => $scope]) {
                $slot = $now->copy()->setTimeFromTimeString($hm);
                if ($slot->gt($now) || $slot->lt($now->copy()->subMinutes(15))) {
                    continue;
                }
                if (! Cache::add("awb-slot:{$user->id}:{$slot->format('Y-m-d H:i')}", 1, now()->addDay())) {
                    continue;
                }
                try {
                    $sent += self::send($user, "Jadwal {$hm} WIB", $scope)['count'];
                } catch (Throwable $e) {
                    Log::warning("AWB schedule {$user->id}: {$e->getMessage()}");
                    Notifier::send($user->id, 'order', 'Kirim resi ke Telegram gagal', mb_substr($e->getMessage(), 0, 200), '/settings/automation');
                }
            }
        }

        return $sent;
    }

    /** Valid, unique "HH:MM" times, sorted. */
    public static function times(mixed $times): array
    {
        return collect(is_array($times) ? $times : [])
            ->filter(fn ($t) => is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t))
            ->unique()->sort()->values()->all();
    }

    /**
     * Send slots from the automation settings: [{time:"08:00", scope:"new"|"all"}], one per time, sorted.
     * "new" = only labels not printed yet; "all" = every ready label, printed or not. Older settings stored only
     * `awb_times` (strings), read as "new".
     *
     * @return list<array{time:string, scope:string}>
     */
    public static function slots(array $settings): array
    {
        $raw = is_array($settings['awb_slots'] ?? null)
            ? $settings['awb_slots']
            : array_map(fn ($t) => ['time' => $t, 'scope' => 'new'], self::times($settings['awb_times'] ?? []));
        $valid = array_filter($raw, fn ($s) => is_array($s) && self::times([$s['time'] ?? null]));

        return collect($valid)
            ->map(fn ($s) => ['time' => $s['time'], 'scope' => ($s['scope'] ?? 'new') === 'all' ? 'all' : 'new'])
            ->unique('time')->sortBy('time')->values()->all();
    }

    /**
     * @param  string  $scope  "new" = only labels not printed yet; "all" = every ready label, printed or not
     * @return array{count:int, failed:array}
     */
    public static function send(User $user, string $label, string $scope = 'new'): array
    {
        $onlyNew = $scope !== 'all';
        $tg = self::telegram($user);
        $chat = $user->integration('telegram')->settings['chat_id'];

        [$orders, $bookings] = AwbBatch::collect($user, $onlyNew ? 'new' : 'all', limit: self::MAX);
        // Parcels already handed over are dropped (their label can't be printed again) and move to "Dikirim".
        [$orders] = AwbBatch::refresh($orders);

        if ($orders->isEmpty() && $bookings->isEmpty()) {
            $tg->sendMessage($chat, "{$label}: tidak ada resi Shopee ".($onlyNew ? 'baru ' : '').'untuk dicetak.');

            return ['count' => 0, 'failed' => []];
        }
        $dir = storage_path('app/awb/'.Str::uuid());
        File::ensureDirectoryExists($dir);
        try {
            [$parts, $doneOrders, $doneBookings, $failed] = AwbBatch::download($dir, $orders, $bookings);
            if (! $parts) {
                throw new RuntimeException('Resi gagal diambil dari Shopee: '.implode('; ', $failed));
            }
            $count = count($doneOrders) + count($doneBookings);
            $caption = "{$label}: {$count} resi Shopee".($onlyNew ? ' belum tercetak' : ' (semua yang siap cetak)')
                .' — '.count($doneOrders).' pesanan, '.count($doneBookings).' Pengiriman Kilat.';
            if ($failed) {
                $caption .= "\nGagal diambil: ".implode('; ', $failed);
            }
            $stamp = now()->setTimezone(Dates::TZ)->format('Ymd-Hi');
            $out = "{$dir}/resi.pdf";
            $printSettings = $user->settings['print_settings'] ?? [];
            if (AwbBatch::render($parts, $out, $printSettings) !== null) {
                $tg->sendDocument($chat, $out, "resi-shopee-{$stamp}.pdf", $caption);
            } else {
                // Post-processing failed: still deliver Shopee's own labels rather than nothing.
                foreach ($parts as $i => $p) {
                    $tg->sendDocument($chat, $p['pdf'], "resi-shopee-{$stamp}-".($i + 1).'.pdf', $i === 0 ? $caption."\n(Format asli Shopee.)" : '');
                }
            }
            \App\Models\Order::whereIn('id', $doneOrders)->update(['awb_printed_at' => now()]);
            ShopeeBooking::whereIn('id', $doneBookings)->update(['awb_printed_at' => now()]);

            return ['count' => $count, 'failed' => $failed];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public static function telegram(User $user): TelegramClient
    {
        $i = $user->integration('telegram');
        if (! $i || ! $i->key('bot_token') || empty($i->settings['chat_id'])) {
            throw new RuntimeException('Telegram belum terhubung. Hubungkan di Pengaturan → Otomatisasi Pesanan.');
        }

        return new TelegramClient($i->key('bot_token'));
    }
}
