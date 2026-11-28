<?php

namespace App\Support;

use App\Models\StockMaster;
use App\Models\User;
use App\Support\Shopee\ShipDeadline;
use App\Support\Telegram\TelegramClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ringkasan laba harian: every morning, yesterday's revenue and net profit (same numbers as the dashboard),
 * plus what needs attention today (losing ads, SKUs out of stock, orders near the Shopee ship-by deadline,
 * orders without HPP). Sent once per WIB day as an in-app/Web Push notification and, when connected, Telegram.
 * Sellers switch it off in Pengaturan → Notifikasi (settings.notify.daily_summary).
 */
class DailySummary
{
    public static function enabled(User $user): bool
    {
        return (bool) ($user->settings['notify']['daily_summary'] ?? true);
    }

    /** Figures for the WIB day before $now (default: yesterday). */
    public static function build(User $user, ?Carbon $now = null): array
    {
        $today = ($now ?? now())->copy()->setTimezone(Dates::TZ)->startOfDay();
        $day = $today->copy()->subDay();
        $s = Analytics::profit($user, $day->copy()->utc(), $day->copy()->endOfDay()->utc())['summary'];
        $p = Analytics::profit($user, $day->copy()->subDay()->utc(), $day->copy()->subDay()->endOfDay()->utc())['summary'];

        $ads = ['rugi' => 0, 'tanpa_penjualan' => 0, 'loss' => 0, 'wasted' => 0];
        try {
            if (\App\Models\ShopeeAdCampaign::where('user_id', $user->id)->exists()) {
                $ads = array_intersect_key(AdsGuard::evaluate($user)['summary'], $ads) + $ads;
            }
        } catch (Throwable) {
            // ads are a bonus line
        }
        $stock = ['habis' => 0, 'kritis' => 0];
        if (StockMaster::where('user_id', $user->id)->exists()) {
            $stock = array_intersect_key(RestockForecast::summary($user), $stock);
        }
        $due = collect(ShipDeadline::due($user));

        return [
            'date' => $day->toDateString(),
            'revenue' => (int) $s['revenue'], 'net_profit' => (int) $s['net_profit'], 'margin' => $s['margin'],
            'orders' => (int) $s['orders'], 'ads' => (int) $s['ads'], 'missing_cost_orders' => (int) $s['missing_cost_orders'],
            'prev_revenue' => (int) $p['revenue'], 'prev_net_profit' => (int) $p['net_profit'],
            'ads_losing' => (int) $ads['rugi'], 'ads_no_sales' => (int) $ads['tanpa_penjualan'], 'ads_loss' => abs((int) $ads['loss']), 'ads_wasted' => (int) $ads['wasted'],
            'stock_out' => (int) $stock['habis'], 'stock_critical' => (int) $stock['kritis'],
            'ship_late' => $due->where('late', true)->count(), 'ship_today' => $due->where('late', false)->count(),
        ];
    }

    /** Nothing sold and nothing to act on: no message. */
    public static function isEmpty(array $d): bool
    {
        return $d['orders'] === 0 && $d['ads'] === 0 && $d['prev_revenue'] === 0
            && ! ($d['ads_losing'] + $d['ads_no_sales'] + $d['stock_out'] + $d['stock_critical'] + $d['ship_late'] + $d['ship_today']);
    }

    /** @return array{0: string, 1: string} title and body */
    public static function text(array $d): array
    {
        $rp = fn (int $n) => ($n < 0 ? '-' : '').'Rp'.number_format(abs($n), 0, ',', '.');
        $change = function (int $now, int $prev): string {
            if ($prev === 0) {
                return '';
            }
            $pct = (int) round(($now - $prev) / abs($prev) * 100);

            return $pct === 0 ? ' (sama dgn hari sebelumnya)' : ' ('.($pct > 0 ? '▲' : '▼').abs($pct).'%)';
        };
        $date = Carbon::parse($d['date'])->locale('id')->translatedFormat('j M');

        $title = $d['orders']
            ? "Kemarin ({$date}): laba bersih {$rp($d['net_profit'])} dari {$d['orders']} pesanan"
            : "Kemarin ({$date}): belum ada pesanan";
        $lines = [
            "Omset {$rp($d['revenue'])}{$change($d['revenue'], $d['prev_revenue'])}",
            "Laba bersih {$rp($d['net_profit'])}{$change($d['net_profit'], $d['prev_net_profit'])}".($d['revenue'] ? ' · margin '.str_replace('.', ',', (string) $d['margin']).'%' : ''),
        ];
        if ($d['ads']) {
            $lines[] = "Biaya iklan {$rp($d['ads'])}";
        }

        $todo = [];
        if ($d['ship_late']) {
            $todo[] = "{$d['ship_late']} pesanan Shopee TERLAMBAT dikirim";
        }
        if ($d['ship_today']) {
            $todo[] = "{$d['ship_today']} pesanan Shopee harus dikirim dalam 24 jam";
        }
        if ($d['ads_losing']) {
            $todo[] = "{$d['ads_losing']} iklan merugi (±{$rp($d['ads_loss'])})";
        }
        if ($d['ads_no_sales']) {
            $todo[] = "{$d['ads_no_sales']} iklan boros tanpa penjualan ({$rp($d['ads_wasted'])})";
        }
        if ($d['stock_out'] || $d['stock_critical']) {
            $todo[] = implode(' · ', array_filter([$d['stock_out'] ? "{$d['stock_out']} SKU habis" : '', $d['stock_critical'] ? "{$d['stock_critical']} SKU hampir habis" : '']));
        }
        if ($d['missing_cost_orders']) {
            $todo[] = "{$d['missing_cost_orders']} pesanan belum ada HPP, laba bisa lebih kecil dari angka di atas";
        }
        if ($todo) {
            $lines[] = '';
            $lines[] = 'Perlu dicek hari ini:';
            foreach ($todo as $t) {
                $lines[] = "• {$t}";
            }
        }

        return [$title, implode("\n", $lines)];
    }

    /** Sends yesterday's summary once per WIB day. Returns false when skipped (off, already sent, nothing to say). */
    public static function send(User $user, ?Carbon $now = null): bool
    {
        if (! self::enabled($user)) {
            return false;
        }
        $d = self::build($user, $now);
        if (self::isEmpty($d) || ! Cache::add("daily_summary:{$user->id}:{$d['date']}", 1, now()->addDays(2))) {
            return false;
        }
        [$title, $body] = self::text($d);
        Notifier::send($user->id, 'summary', $title, $body, '/dashboard');
        try {
            $i = $user->integration('telegram');
            if ($i && $i->key('bot_token') && ! empty($i->settings['chat_id'])) {
                (new TelegramClient($i->key('bot_token')))->sendMessage($i->settings['chat_id'], "📊 {$title}\n\n{$body}\n\nDetail: Pesanin → Dashboard");
            }
        } catch (Throwable) {
            // the in-app notification is already there
        }

        return true;
    }
}
