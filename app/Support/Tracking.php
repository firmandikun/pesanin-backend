<?php

namespace App\Support;

use App\Models\Order;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Cek resi": courier checkpoints for an order.
 * Shopee orders come from Shopee (get_tracking_info); website/manual orders from BinderByte when
 * BINDERBYTE_API_KEY is set, otherwise from the order's own status history. Every answer also
 * carries the courier's public tracking page.
 */
class Tracking
{
    /** Courier name fragment => [BinderByte code, public tracking page]. */
    private const COURIERS = [
        'spx' => ['spx', 'https://spx.co.id/track'],
        'shopee' => ['spx', 'https://spx.co.id/track'],
        'j&t cargo' => ['jtcargo', 'https://www.jtcargo.id/trackingQuery'],
        'j&t' => ['jnt', 'https://jet.co.id/track'],
        'jnt' => ['jnt', 'https://jet.co.id/track'],
        'jne' => ['jne', 'https://www.jne.co.id/tracking-package'],
        'sicepat' => ['sicepat', 'https://www.sicepat.com/checkAwb'],
        'anteraja' => ['anteraja', 'https://anteraja.id/tracking'],
        'ninja' => ['ninja', 'https://www.ninjaxpress.co/id-id/tracking'],
        'id express' => ['ide', 'https://idexpress.com/lacak-paket/'],
        'idexpress' => ['ide', 'https://idexpress.com/lacak-paket/'],
        'lion' => ['lion', 'https://lionparcel.com/track/stt'],
        'pos' => ['pos', 'https://www.posindonesia.co.id/id/tracking'],
        'tiki' => ['tiki', 'https://www.tiki.id/id/track'],
        'sap' => ['sap', 'https://www.sap-express.id/layanan/tracking'],
        'wahana' => ['wahana', 'https://www.wahana.com/'],
        'gosend' => ['gosend', null],
        'grab' => ['grab', null],
    ];

    public static function courier(?string $name): array
    {
        $n = strtolower((string) $name);
        foreach (self::COURIERS as $key => $c) {
            if ($n !== '' && str_contains($n, $key)) {
                return $c;
            }
        }

        return [null, null];
    }

    /** @return array{tracking_number: ?string, courier: ?string, status: ?string, source: string, track_url: ?string, events: array<int, array{time: ?string, text: string, location: ?string}>, note: ?string} */
    public static function for(Order $order): array
    {
        [$code, $url] = self::courier($order->courier);
        $base = [
            'tracking_number' => $order->tracking_number, 'courier' => $order->courier,
            'status' => null, 'source' => 'pesanin', 'track_url' => $url, 'events' => [], 'note' => null,
        ];

        if ($order->channel === 'shopee' && $order->marketplaceShop && ! $order->marketplaceShop->is_demo) {
            try {
                $info = (new ShopeeClient)->trackingInfo($order->marketplaceShop, $order->external_order_sn);
                $events = collect($info['tracking_info'] ?? [])->map(fn ($e) => [
                    'time' => isset($e['update_time']) ? Carbon::createFromTimestamp($e['update_time'])->toIso8601String() : null,
                    'text' => trim($e['description'] ?? ($e['logistics_status'] ?? '')), 'location' => null,
                ])->sortByDesc('time')->values()->all();

                return ['status' => self::shopeeStatus($info['logistics_status'] ?? null), 'source' => 'shopee', 'events' => $events] + $base;
            } catch (ShopeeException $e) {
                $base['note'] = 'Shopee: '.$e->getMessage();
            }
        }

        // Seller's Biteship: tracks any waybill of a supported courier, booked through Biteship or not.
        $biteship = $order->channel !== 'shopee' ? \App\Support\Biteship\Biteship::for($order->user?->integration('biteship')) : null;
        $bCourier = $order->courier_code ? strtok($order->courier_code, ':') : self::biteshipCode($order->courier);
        if ($biteship && $order->tracking_number && $bCourier) {
            try {
                $t = $biteship->tracking($order->tracking_number, $bCourier);
                $events = collect($t['history'] ?? [])->map(fn ($e) => [
                    'time' => isset($e['updated_at']) ? Carbon::parse($e['updated_at'])->toIso8601String() : null,
                    'text' => trim($e['note'] ?? ($e['status'] ?? '')), 'location' => null,
                ])->sortByDesc('time')->values()->all();

                return ['status' => self::biteshipStatus($t['status'] ?? null), 'source' => 'biteship', 'events' => $events, 'track_url' => $t['link'] ?? $url] + $base;
            } catch (Throwable $e) {
                $base['note'] = 'Biteship: '.$e->getMessage();
            }
        }

        // Seller's RajaOngkir: waybill tracking for the major couriers.
        $ro = $order->channel !== 'shopee' ? \App\Support\RajaOngkir\RajaOngkir::for($order->user?->integration('rajaongkir')) : null;
        if ($ro && $order->tracking_number && $bCourier) {
            try {
                $t = $ro->track($order->tracking_number, $bCourier, $order->phone);
                $events = collect($t['manifest'] ?? [])->map(fn ($m) => [
                    'time' => ! empty($m['manifest_date']) ? Carbon::parse(trim($m['manifest_date'].' '.($m['manifest_time'] ?? '')))->toIso8601String() : null,
                    'text' => trim($m['manifest_description'] ?? ''), 'location' => $m['city_name'] ?? null,
                ])->sortByDesc('time')->values()->all();

                return ['status' => $t['delivery_status']['status'] ?? ($t['summary']['status'] ?? null), 'source' => 'rajaongkir', 'events' => $events] + $base;
            } catch (Throwable $e) {
                $base['note'] = 'RajaOngkir: '.$e->getMessage();
            }
        }

        $key = config('services.binderbyte.key');
        if ($key && $code && $order->tracking_number && $order->channel !== 'shopee') {
            try {
                $res = Http::timeout(10)->get('https://api.binderbyte.com/v1/track', [
                    'api_key' => $key, 'courier' => $code, 'awb' => $order->tracking_number,
                ])->json();
                if (($res['status'] ?? 0) == 200) {
                    $events = collect($res['data']['history'] ?? [])->map(fn ($e) => [
                        'time' => isset($e['date']) ? Carbon::parse($e['date'])->toIso8601String() : null,
                        'text' => $e['desc'] ?? '', 'location' => $e['location'] ?? null,
                    ])->values()->all();

                    return ['status' => $res['data']['summary']['status'] ?? null, 'source' => 'binderbyte', 'events' => $events] + $base;
                }
                $base['note'] = $res['message'] ?? 'Resi belum ditemukan di ekspedisi.';
            } catch (Throwable) {
                $base['note'] = 'Layanan lacak sedang tidak bisa dihubungi.';
            }
        }

        return ['events' => self::history($order)] + $base;
    }

    /** Fallback timeline from what Pesanin itself knows about the order. */
    private static function history(Order $order): array
    {
        $rows = [
            [$order->created_at, 'Pesanan dibuat'],
            [$order->paid_at, 'Pembayaran diterima'],
            [$order->shipped_at, 'Paket diserahkan ke '.($order->courier ?: 'ekspedisi').($order->tracking_number ? " · resi {$order->tracking_number}" : '')],
            [in_array($order->shipping_status, ['delivered'], true) ? ($order->completed_at ?? $order->updated_at) : null, 'Paket diterima pembeli'],
        ];

        return collect($rows)->filter(fn ($r) => $r[0])->map(fn ($r) => [
            'time' => Carbon::parse($r[0])->toIso8601String(), 'text' => $r[1], 'location' => null,
        ])->sortByDesc('time')->values()->all();
    }

    private static function biteshipCode(?string $courier): ?string
    {
        $n = strtolower((string) $courier);
        foreach (['j&t cargo' => null, 'j&t' => 'jnt', 'jne' => 'jne', 'sicepat' => 'sicepat', 'sap' => 'sap', 'anteraja' => 'anteraja',
            'idexpress' => 'idexpress', 'id express' => 'idexpress', 'ninja' => 'ninja', 'lion' => 'lion', 'pos' => 'pos', 'tiki' => 'tiki'] as $k => $code) {
            if ($n !== '' && str_contains($n, $k)) {
                return $code;
            }
        }

        return null;
    }

    private static function biteshipStatus(?string $s): ?string
    {
        return match ($s) {
            'confirmed', 'allocated', 'picking_up' => 'Menunggu dijemput kurir',
            'picked' => 'Sudah dijemput kurir',
            'dropping_off' => 'Dalam pengiriman',
            'delivered' => 'Terkirim',
            'return_in_transit' => 'Dalam proses retur',
            'returned' => 'Dikembalikan ke pengirim',
            'cancelled', 'rejected' => 'Dibatalkan',
            default => $s,
        };
    }

    private static function shopeeStatus(?string $s): ?string
    {
        return match ($s) {
            'LOGISTICS_READY', 'LOGISTICS_REQUEST_CREATED' => 'Menunggu dijemput / diantar',
            'LOGISTICS_PICKUP_DONE' => 'Dalam pengiriman',
            'LOGISTICS_DELIVERY_DONE' => 'Terkirim',
            'LOGISTICS_DELIVERY_FAILED', 'LOGISTICS_PICKUP_FAILED' => 'Gagal kirim',
            'LOGISTICS_COD_REJECTED' => 'COD ditolak',
            'LOGISTICS_INVALID', 'LOGISTICS_LOST' => 'Bermasalah',
            default => $s,
        };
    }
}
