<?php

namespace App\Support;

use App\Models\AwbPrintJob;
use App\Models\Order;
use App\Models\ShopeeBooking;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Cetak resi di latar belakang: for hundreds or thousands of labels the browser would have to download and format
 * every page itself. Here the server does it (AwbBatch) and the seller gets a notification with one PDF to download,
 * even if they closed the tab.
 */
class AwbPrintJobs
{
    public const MAX = 5000;

    /** Files are kept this many days. */
    public const KEEP_DAYS = 3;

    public static function start(User $user, string $scope, array $orderIds = [], array $bookingIds = []): AwbPrintJob
    {
        $running = AwbPrintJob::where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subMinutes(30))->exists();
        abort_if($running, 422, 'Masih ada cetak resi yang sedang diproses. Tunggu sampai selesai.');
        $job = new AwbPrintJob(['scope' => $scope, 'order_ids' => $orderIds ?: null, 'booking_ids' => $bookingIds ?: null]);
        $job->user_id = $user->id;
        $job->save();

        $php = (new PhpExecutableFinder)->find(false);
        if ($php && function_exists('exec') && ! app()->runningUnitTests()) {
            // Detached: thousands of labels take minutes, far longer than a web request may run.
            @exec(sprintf('nohup %s %s awb:print-job %d >> %s 2>&1 &', escapeshellarg($php), escapeshellarg(base_path('artisan')), $job->id, escapeshellarg(storage_path('logs/awb-print.log'))));
        } else {
            self::run($job);
        }

        return $job->fresh();
    }

    public static function run(AwbPrintJob $job): AwbPrintJob
    {
        $user = $job->user;
        $dir = storage_path("app/awb-print/{$job->id}");
        File::ensureDirectoryExists($dir);
        try {
            $job->update(['status' => 'running', 'stage' => 'checking']);
            [$orders, $bookings] = AwbBatch::collect($user, $job->scope, $job->order_ids ?? [], $job->booking_ids ?? [], self::MAX);
            $missing = $job->scope === 'selected' ? count($job->order_ids ?? []) - $orders->count() + count($job->booking_ids ?? []) - $bookings->count() : 0;
            [$orders, $skipped] = AwbBatch::refresh($orders);
            $total = $orders->count() + $bookings->count();
            $errors = $missing > 0 ? ["{$missing} pesanan belum punya nomor resi atau sudah tidak bisa dicetak."] : [];
            $job->update(['total' => $total, 'skipped' => $skipped, 'errors' => $errors, 'stage' => 'downloading']);
            if (! $total) {
                throw new \RuntimeException('Tidak ada resi Shopee yang bisa dicetak.');
            }

            [$parts, $doneOrders, $doneBookings, $failed] = AwbBatch::download($dir, $orders, $bookings, fn ($done) => $job->update(['done' => $done]));
            $errors = array_merge($errors, $failed);
            if (! $parts) {
                throw new \RuntimeException('Resi gagal diambil dari Shopee: '.implode('; ', $failed));
            }
            $job->update(['stage' => 'formatting', 'errors' => $errors]);
            $out = "{$dir}/resi.pdf";
            $pages = AwbBatch::render($parts, $out, $user->settings['print_settings'] ?? [], 900);
            if ($pages === null) {
                // Formatter unavailable: still give Shopee's own labels, merged as they are.
                $pages = self::mergeRaw($parts, $out);
                $errors[] = 'Label dicetak dalam format asli Shopee (pemformat label server belum terpasang).';
            }
            Order::whereIn('id', $doneOrders)->update(['awb_printed_at' => now()]);
            ShopeeBooking::whereIn('id', $doneBookings)->update(['awb_printed_at' => now()]);
            foreach ($parts as $p) {
                @unlink($p['pdf']);
            }
            $count = count($doneOrders) + count($doneBookings);
            $job->update(['status' => 'done', 'stage' => null, 'file' => $out, 'pages' => $pages, 'done' => $count, 'errors' => $errors, 'finished_at' => now()]);
            Alert::send($user, 'print', "{$count} resi Shopee siap dicetak", ($pages ? "{$pages} halaman" : '').($skipped ? ' · '.count($skipped).' sudah dikirim, tidak dicetak' : '').($failed ? ' · sebagian gagal diambil' : ''),
                "/marketplace/orders?print_job={$job->id}", '🖨️', 'Buka Pesanin → Pesanan & Pengiriman untuk mengunduh PDF-nya.');
        } catch (Throwable $e) {
            $job->update(['status' => 'failed', 'stage' => null, 'errors' => array_merge($job->errors ?? [], [mb_substr($e->getMessage(), 0, 300)]), 'finished_at' => now()]);
            Notifier::send($user->id, 'print', 'Cetak resi gagal', mb_substr($e->getMessage(), 0, 200), "/marketplace/orders?print_job={$job->id}");
        }

        return $job->fresh();
    }

    /** Fallback when the Node formatter is missing: a single Shopee document is still usable as it is. */
    private static function mergeRaw(array $parts, string $out): int
    {
        if (count($parts) !== 1) {
            throw new \RuntimeException('Pemformat label di server belum terpasang (jalankan npm ci di backend/scripts/awb).');
        }
        copy($parts[0]['pdf'], $out);

        return 0;
    }

    /** Daily: delete finished jobs' files after KEEP_DAYS. */
    public static function prune(): int
    {
        $n = 0;
        AwbPrintJob::where('created_at', '<', now()->subDays(self::KEEP_DAYS))->each(function ($j) use (&$n) {
            File::deleteDirectory(storage_path("app/awb-print/{$j->id}"));
            $j->delete();
            $n++;
        });

        return $n;
    }

    public static function present(AwbPrintJob $j): array
    {
        return ['id' => $j->id, 'scope' => $j->scope, 'status' => $j->status, 'stage' => $j->stage, 'total' => $j->total, 'done' => $j->done, 'pages' => $j->pages,
            'skipped' => $j->skipped ?? [], 'errors' => $j->errors ?? [], 'created_at' => $j->created_at, 'finished_at' => $j->finished_at,
            'has_file' => $j->status === 'done' && $j->file && is_file($j->file)];
    }
}
