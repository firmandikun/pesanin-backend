<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Telegram\TelegramClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Kesehatan akun Shopee (v2.account_health), read live and cached 30 min per shop: overall performance with every
 * metric against its target, this quarter's penalty points, late orders and listings with issues. A daily check
 * alerts the seller on new penalty points, late orders or a performance drop.
 */
class ShopeeHealth
{
    public const RATING = [1 => 'Buruk', 2 => 'Perlu Ditingkatkan', 3 => 'Baik', 4 => 'Sangat Baik'];

    public const METRIC_TYPE = [1 => 'Performa Pengiriman', 2 => 'Performa Produk', 3 => 'Pelayanan Pembeli'];

    /** Seller Centre's Indonesian names for the common metrics; others keep Shopee's name. */
    public const METRICS = [
        -1 => 'Chat Tidak Dibalas', 1 => 'Tingkat Keterlambatan Pengiriman', 3 => 'Tingkat Pesanan Tidak Terselesaikan', 4 => 'Masa Pengemasan',
        11 => 'Persentase Chat Dibalas', 12 => 'Persentase Produk Pre-order', 15 => 'Hari Melanggar Batas Pre-order', 21 => 'Kecepatan Chat Dibalas',
        22 => 'Penilaian Toko', 23 => 'Jumlah Chat Tidak Dibalas', 25 => 'Tingkat Serah Terima Cepat', 27 => 'Tingkat Gagal Pickup Tepat Waktu',
        29 => 'Rata-rata Waktu Balas Chat', 42 => 'Tingkat Pembatalan', 43 => 'Tingkat Pengembalian Barang/Dana', 52 => 'Pelanggaran Produk Berat',
        53 => 'Pelanggaran Produk Lainnya', 54 => 'Produk yang Dilarang', 55 => 'Produk Palsu / Pelanggaran HKI', 56 => 'Produk Spam', 95 => 'Kepuasan Pelanggan',
    ];

    public const UNIT = [1 => '', 2 => '%', 3 => ' detik', 4 => ' hari', 5 => ' jam'];

    public const VIOLATION = [
        5 => 'Tingkat keterlambatan pengiriman tinggi', 6 => 'Tingkat pesanan tidak terselesaikan tinggi', 7 => 'Banyak pesanan tidak terselesaikan',
        8 => 'Banyak pesanan terlambat dikirim', 9 => 'Produk dilarang', 10 => 'Produk palsu / pelanggaran HKI', 11 => 'Spam', 12 => 'Menyalin/mencuri foto',
        13 => 'Upload ulang produk yang dihapus', 16 => 'Persentase produk pre-order tinggi', 19 => 'Alamat retur palsu', 20 => 'Penipuan pengiriman',
        21 => 'Banyak chat tidak dibalas', 22 => 'Balasan chat kasar', 23 => 'Meminta pembeli membatalkan pesanan', 24 => 'Balasan ulasan kasar',
        25 => 'Melanggar kebijakan pengembalian', 3030 => 'Transaksi di luar Shopee', 3032 => 'Mengirim paket kosong / tidak lengkap',
        3052 => 'Membocorkan data pembeli di balasan ulasan', 3054 => 'Order brushing', 3058 => 'Kategori produk salah', 3060 => 'Pesanan tidak terselesaikan sangat tinggi',
        3074 => 'Transaksi di luar Shopee lewat chat', 4130 => 'Kualitas produk buruk',
    ];

    public const LISTING_REASON = [1 => 'Produk dilarang', 2 => 'Produk palsu', 3 => 'Spam', 4 => 'Foto tidak pantas', 5 => 'Informasi kurang lengkap', 6 => 'Perlu perbaikan (Mall)', 7 => 'Perlu perbaikan'];

    /** Everything for one shop. $fresh skips the 30-minute cache. */
    public static function shop(MarketplaceShop $shop, bool $fresh = false): array
    {
        $key = "shopee:health:{$shop->id}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(30), function () use ($shop) {
            $api = new ShopeeClient;
            $part = function (callable $fn) {
                try {
                    return [$fn(), null];
                } catch (Throwable $e) {
                    return [null, mb_substr($e->getMessage(), 0, 200)];
                }
            };
            [$perf, $perfErr] = $part(fn () => $api->shopPerformance($shop));
            [$points, $pointsErr] = $part(fn () => $api->penaltyPoints($shop));
            [$late, $lateErr] = $part(fn () => $api->lateOrders($shop));
            [$issues, $issuesErr] = $part(fn () => $api->listingsWithIssues($shop));

            $metrics = collect($perf['metric_list'] ?? [])->map(function ($m) {
                $target = $m['target'] ?? [];
                $ok = self::meets($m['current_period'] ?? null, $target['comparator'] ?? null, $target['value'] ?? null);

                return [
                    'id' => (int) $m['metric_id'], 'parent_id' => (int) ($m['parent_metric_id'] ?? 0), 'type' => (int) ($m['metric_type'] ?? 0),
                    'name' => self::METRICS[(int) $m['metric_id']] ?? ($m['metric_name'] ?? 'Metrik'),
                    'current' => $m['current_period'] ?? null, 'last' => $m['last_period'] ?? null, 'unit' => self::UNIT[(int) ($m['unit'] ?? 1)] ?? '',
                    'target' => isset($target['value']) ? ($target['comparator'] ?? '').' '.$target['value'] : null, 'ok' => $ok,
                ];
            })->values();
            $orders = Order::where('marketplace_shop_id', $shop->id)->whereIn('external_order_sn', collect($late ?? [])->pluck('order_sn')->all() ?: [''])
                ->get(['id', 'external_order_sn', 'customer_name', 'external_status'])->keyBy('external_order_sn');
            $names = MarketplaceListing::where('marketplace_shop_id', $shop->id)->whereIn('item_id', collect($issues ?? [])->pluck('item_id')->all() ?: [0])
                ->get(['item_id', 'name', 'image'])->keyBy('item_id');

            return [
                'shop' => ['id' => $shop->id, 'name' => $shop->shop_name],
                'checked_at' => now()->toIso8601String(),
                'errors' => array_filter(['performa' => $perfErr, 'penalti' => $pointsErr, 'terlambat' => $lateErr, 'listing' => $issuesErr]),
                'overall' => $perf ? [
                    'rating' => (int) ($perf['overall_performance']['rating'] ?? 0),
                    'label' => self::RATING[(int) ($perf['overall_performance']['rating'] ?? 0)] ?? '-',
                    'failed' => [
                        'Performa Pengiriman' => (int) ($perf['overall_performance']['fulfillment_failed'] ?? 0),
                        'Performa Produk' => (int) ($perf['overall_performance']['listing_failed'] ?? 0),
                        'Pelayanan Pembeli' => (int) ($perf['overall_performance']['custom_service_failed'] ?? 0),
                    ],
                ] : null,
                // Plain arrays only: this is cached, and the cache does not restore objects (Collections).
                'metrics' => $metrics->groupBy('type')->map(fn ($g, $t) => ['type' => self::METRIC_TYPE[$t] ?? 'Lainnya', 'metrics' => $g->values()->all()])->values()->all(),
                'penalty' => [
                    'total' => (int) collect($points ?? [])->sum('latest_point_num'),
                    'records' => collect($points ?? [])->sortByDesc('issue_time')->map(fn ($p) => [
                        'id' => (string) ($p['reference_id'] ?? ''), 'at' => isset($p['issue_time']) ? date('c', (int) $p['issue_time']) : null,
                        'points' => (int) ($p['latest_point_num'] ?? 0), 'original' => (int) ($p['original_point_num'] ?? 0),
                        'reason' => self::VIOLATION[(int) ($p['violation_type'] ?? 0)] ?? 'Pelanggaran #'.($p['violation_type'] ?? '?'),
                    ])->values()->all(),
                ],
                'late_orders' => collect($late ?? [])->map(fn ($o) => [
                    'order_sn' => $o['order_sn'], 'deadline' => isset($o['shipping_deadline']) ? date('c', (int) $o['shipping_deadline']) : null,
                    'late_days' => (int) ($o['late_by_days'] ?? 0), 'order_id' => $orders[$o['order_sn']]->id ?? null,
                    'buyer' => $orders[$o['order_sn']]->customer_name ?? null, 'status' => $orders[$o['order_sn']]->external_status ?? null,
                ])->sortByDesc('late_days')->values()->all(),
                'listings' => collect($issues ?? [])->map(fn ($l) => [
                    'item_id' => (int) $l['item_id'], 'reason' => self::LISTING_REASON[(int) ($l['reason'] ?? 0)] ?? 'Bermasalah',
                    'name' => $names[$l['item_id']]->name ?? "Produk {$l['item_id']}", 'image' => $names[$l['item_id']]->image ?? null,
                ])->values()->all(),
            ];
        });
    }

    /** Whether a metric value meets its target (null when Shopee gives no target). */
    public static function meets($value, ?string $comparator, $target): ?bool
    {
        if ($value === null || $target === null || ! $comparator) {
            return null;
        }
        $v = (float) $value;
        $t = (float) $target;

        return match (trim($comparator)) {
            '<' => $v < $t, '<=' => $v <= $t, '>' => $v > $t, '>=' => $v >= $t, '=' => abs($v - $t) < 1e-9, default => null,
        };
    }

    /**
     * Daily: alert on NEW penalty points (each record once), on orders currently late, and when the overall
     * performance is below "Baik". @return int alerts sent
     */
    public static function check(User $user): int
    {
        $lines = [];
        foreach ($user->marketplaceShops()->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false)->get() as $shop) {
            $h = self::shop($shop, true);
            $seenKey = "shopee:health:seen-penalty:{$shop->id}";
            $seen = Cache::get($seenKey, []);
            $new = collect($h['penalty']['records'])->filter(fn ($r) => $r['id'] && ! in_array($r['id'], $seen, true));
            Cache::forever($seenKey, collect($h['penalty']['records'])->pluck('id')->filter()->values()->all());
            foreach ($new as $r) {
                $lines[] = "• {$shop->shop_name}: +{$r['points']} poin penalti, {$r['reason']}";
            }
            if ($n = count($h['late_orders'])) {
                $lines[] = "• {$shop->shop_name}: {$n} pesanan terlambat dikirim";
            }
            if ($h['overall'] && $h['overall']['rating'] > 0 && $h['overall']['rating'] < 3) {
                $lines[] = "• {$shop->shop_name}: performa toko {$h['overall']['label']}";
            }
        }
        if (! $lines) {
            return 0;
        }
        $title = 'Kesehatan akun Shopee perlu dicek';
        Notifier::send($user->id, 'health', $title, implode("\n", $lines), '/marketplace/health');
        try {
            $i = $user->integration('telegram');
            if ($i && $i->key('bot_token') && ! empty($i->settings['chat_id'])) {
                (new TelegramClient($i->key('bot_token')))->sendMessage($i->settings['chat_id'], "🩺 {$title}\n".implode("\n", $lines));
            }
        } catch (Throwable) {
            // in-app notification already sent
        }

        return count($lines);
    }
}
