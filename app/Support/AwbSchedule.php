<?php

namespace App\Support;

use App\Models\ShopeeBooking;
use App\Models\User;
use App\Support\Shopee\ShopeeAwb;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeeSync;
use App\Support\Telegram\TelegramClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
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
            foreach (self::times($s['awb_times']) as $hm) {
                $slot = $now->copy()->setTimeFromTimeString($hm);
                if ($slot->gt($now) || $slot->lt($now->copy()->subMinutes(15))) {
                    continue;
                }
                if (! Cache::add("awb-slot:{$user->id}:{$slot->format('Y-m-d H:i')}", 1, now()->addDay())) {
                    continue;
                }
                try {
                    $sent += self::send($user, "Jadwal {$hm} WIB")['count'];
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

    /** @return array{count:int, failed:array} */
    public static function send(User $user, string $label): array
    {
        $tg = self::telegram($user);
        $chat = $user->integration('telegram')->settings['chat_id'];

        $notDemo = fn ($q) => $q->where('is_demo', false);
        $orders = $user->orders()->active()->where('channel', 'shopee')->whereIn('external_status', ['PROCESSED', 'RETRY_SHIP'])
            ->whereNotNull('tracking_number')->where('tracking_number', '!=', '')->whereNull('awb_printed_at')
            ->whereHas('marketplaceShop', $notDemo)->with('marketplaceShop')->orderBy('id')->limit(self::MAX)->get();
        $bookings = ShopeeBooking::where('user_id', $user->id)->where('booking_status', 'PROCESSED')
            ->whereNotNull('tracking_number')->where('tracking_number', '!=', '')->whereNull('awb_printed_at')
            ->whereHas('marketplaceShop', $notDemo)->with('marketplaceShop')->orderBy('id')->limit(self::MAX)->get();

        if ($orders->isEmpty() && $bookings->isEmpty()) {
            $tg->sendMessage($chat, "{$label}: tidak ada resi Shopee baru untuk dicetak.");

            return ['count' => 0, 'failed' => []];
        }
        ShopeeSync::withListingSkus($orders);

        $dir = storage_path('app/awb/'.Str::uuid());
        File::ensureDirectoryExists($dir);
        try {
            [$parts, $doneOrders, $doneBookings, $failed] = self::download($dir, $orders, $bookings);
            if (! $parts) {
                throw new RuntimeException('Resi gagal diambil dari Shopee: '.implode('; ', $failed));
            }
            $count = count($doneOrders) + count($doneBookings);
            $caption = "{$label}: {$count} resi Shopee (".count($doneOrders).' pesanan, '.count($doneBookings).' Pengiriman Kilat).';
            if ($failed) {
                $caption .= "\nGagal diambil: ".implode('; ', $failed);
            }
            $stamp = now()->setTimezone(Dates::TZ)->format('Ymd-Hi');
            $out = "{$dir}/resi.pdf";
            if (self::render($parts, $out)) {
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

    /** Shopee batches labels per shop + courier, at most 50 per document. */
    private static function download(string $dir, $orders, $bookings): array
    {
        $awb = new ShopeeAwb;
        $parts = [];
        $doneOrders = [];
        $doneBookings = [];
        $failed = [];
        foreach ($orders->groupBy(fn ($o) => $o->marketplace_shop_id.'|'.$o->courier) as $group) {
            foreach ($group->chunk(50) as $chunk) {
                try {
                    $path = "{$dir}/p".count($parts).'.pdf';
                    file_put_contents($path, $awb->orders($chunk->first()->marketplaceShop, $chunk->values()));
                    $parts[] = ['pdf' => $path, 'orders' => $chunk->map(fn ($o) => ['sns' => [$o->external_order_sn], 'skus' => self::skus($o->items)])->values()->all()];
                    array_push($doneOrders, ...$chunk->pluck('id')->all());
                } catch (ShopeeException $e) {
                    $failed[] = $chunk->pluck('external_order_sn')->implode(', ').' ('.$e->getMessage().')';
                }
            }
        }
        foreach ($bookings->groupBy(fn ($b) => $b->marketplace_shop_id.'|'.$b->shipping_carrier) as $group) {
            foreach ($group->chunk(50) as $chunk) {
                try {
                    $path = "{$dir}/p".count($parts).'.pdf';
                    file_put_contents($path, $awb->bookings($chunk->first()->marketplaceShop, $chunk->values()));
                    $parts[] = ['pdf' => $path, 'orders' => $chunk->map(fn ($b) => ['sns' => array_values(array_filter([$b->booking_sn, $b->order_sn])), 'skus' => self::skus($b->items)])->values()->all()];
                    array_push($doneBookings, ...$chunk->pluck('id')->all());
                } catch (ShopeeException $e) {
                    $failed[] = $chunk->pluck('booking_sn')->implode(', ').' ('.$e->getMessage().')';
                }
            }
        }

        return [$parts, $doneOrders, $doneBookings, $failed];
    }

    /** Label SKU per item: the variation SKU, else the product SKU. */
    private static function skus(?array $items): array
    {
        return collect($items ?? [])->map(fn ($i) => ($i['model_sku'] ?? null) ?: (($i['item_sku'] ?? null) ?: (($i['sku'] ?? null) ?: '-')))->values()->all();
    }

    /** Merge + post-process with the Node script (scripts/awb). False when Node or the script isn't available. */
    private static function render(array $parts, string $out): bool
    {
        $node = config('services.node.binary') ?: (new ExecutableFinder)->find('node', null, ['/usr/local/bin', '/usr/bin']);
        $script = base_path('scripts/awb/awb.mjs');
        if (! $node || ! is_file($script) || ! is_dir(base_path('scripts/awb/node_modules'))) {
            return false;
        }
        $job = dirname($out).'/job.json';
        file_put_contents($job, json_encode(['output' => $out, 'parts' => $parts]));
        $p = new Process([$node, $script, $job], base_path('scripts/awb'));
        $p->setTimeout(180);
        $p->run();
        if (! $p->isSuccessful() || ! is_file($out)) {
            Log::warning('AWB render failed: '.mb_substr($p->getErrorOutput() ?: $p->getOutput(), 0, 500));

            return false;
        }

        return true;
    }
}
