<?php

namespace App\Support\Shopee;

use App\Models\Order;
use App\Models\ShopeeBooking;
use Illuminate\Support\Facades\Cache;

/**
 * Arranges shipment for a single READY_TO_SHIP Shopee order or Pengiriman Kilat reservation (booking).
 * Shared by the manual "Atur Pengiriman" action and the background auto-arrange (OrderAutomation).
 */
class ShopeeShipper
{
    public function __construct(private ShopeeClient $api = new ShopeeClient) {}

    /**
     * @param  array  $opts  method ('auto'|'pickup'|'dropoff'|'non_integrated'), and optional
     *                       pickup_time_id, branch_id, slug, sender_real_name, tracking_number.
     * @return array{ok: bool, error: ?string}
     */
    public function arrange(Order $order, array $opts = []): array
    {
        if ($order->external_status === 'PENDING') {
            return ['ok' => false, 'error' => 'pesanan masih Tertunda (sedang ditinjau Shopee), atur pengiriman setelah Shopee melepasnya'];
        }
        if (! in_array($order->external_status, ['READY_TO_SHIP', 'IN_CANCEL'], true)) {
            return ['ok' => false, 'error' => 'bukan pesanan siap kirim ('.$order->external_status.')'];
        }
        $shop = $order->marketplaceShop;
        // Sample shop: simulate what Shopee does (PROCESSED + tracking number issued).
        if ($shop->is_demo) {
            $order->update(['external_status' => 'PROCESSED', 'status' => 'processing', 'tracking_number' => 'SPXID0'.random_int(40000000, 49999999)]);

            return ['ok' => true, 'error' => null];
        }
        try {
            if ($order->external_status === 'IN_CANCEL') {
                try {
                    $this->api->handleBuyerCancellation($shop, $order->external_order_sn, 'REJECT');
                } catch (ShopeeException) {
                    // If Shopee allows shipping directly or already cleared IN_CANCEL, proceed to ship_order.
                }
            }
            $param = Cache::get("shopee:ship_param:{$shop->id}:{$order->external_order_sn}")
                ?? ($order->courier ? Cache::get("shopee:ship_param_courier:{$shop->id}:{$order->courier}") : null)
                ?? $this->api->shippingParameter($shop, $order->external_order_sn);
            $needed = $param['info_needed'] ?? [];
            $use = $this->pickMethod($opts['method'] ?? 'auto', $needed);
            if (! array_key_exists($use, $needed)) {
                throw new ShopeeException(match ($use) {
                    'pickup' => 'ekspedisi ini tidak bisa dijemput, pilih drop-off',
                    'dropoff' => 'ekspedisi ini tidak bisa drop-off, pilih pickup',
                    default => 'metode pengiriman tidak tersedia',
                });
            }
            $this->api->shipOrder($shop, $order->external_order_sn, [$use => $this->methodBody($use, (array) $needed[$use], $param, $opts)]);
            ShopeeSync::markArranged($order);
            try {
                if ($tn = $this->api->trackingNumber($shop, $order->external_order_sn)) {
                    $order->update(['tracking_number' => $tn]);
                }
            } catch (ShopeeException) {
                // issued asynchronously; refreshAwb / next sync picks it up
            }

            return ['ok' => true, 'error' => null];
        } catch (ShopeeException $e) {
            // Shopee rejects manual arranging for Advance Fulfilment / Shopee-fulfilled orders; remember so neither the
            // UI nor the automation keep trying (Shopee issues the AWB automatically).
            if (preg_match('/manually arrange|advance fulfil/i', $e->getMessage())) {
                $order->update(['shopee_auto_ship' => true]);
            }

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Arrange shipment for a READY_TO_SHIP "Pengiriman Kilat" reservation (Shopee booking).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function arrangeBooking(ShopeeBooking $booking, array $opts = []): array
    {
        if ($booking->booking_status !== 'READY_TO_SHIP') {
            return ['ok' => false, 'error' => 'bukan reservasi siap kirim ('.$booking->booking_status.')'];
        }
        $shop = $booking->marketplaceShop;
        if ($shop->is_demo) {
            $booking->update(['booking_status' => 'PROCESSED', 'tracking_number' => 'SPXID0'.random_int(40000000, 49999999)]);

            return ['ok' => true, 'error' => null];
        }
        try {
            $param = Cache::get("shopee:bk_ship_param:{$shop->id}:{$booking->booking_sn}")
                ?? ($booking->shipping_carrier ? Cache::get("shopee:bk_ship_param_courier:{$shop->id}:{$booking->shipping_carrier}") : null)
                ?? $this->api->bookingShippingParameter($shop, $booking->booking_sn);
            $needed = $param['info_needed'] ?? [];
            $use = $this->pickMethod($opts['method'] ?? 'auto', $needed);
            if (! array_key_exists($use, $needed)) {
                throw new ShopeeException(match ($use) {
                    'pickup' => 'ekspedisi ini tidak bisa dijemput, pilih drop-off',
                    'dropoff' => 'ekspedisi ini tidak bisa drop-off, pilih pickup',
                    default => 'metode pengiriman tidak tersedia',
                });
            }
            $this->api->shipBooking($shop, $booking->booking_sn, [$use => $this->methodBody($use, (array) $needed[$use], $param, $opts)]);
            ShopeeSync::markBookingArranged($booking);
            try {
                if ($tn = $this->api->bookingTrackingNumber($shop, $booking->booking_sn)) {
                    $booking->update(['tracking_number' => $tn]);
                }
            } catch (ShopeeException) {
                // issued asynchronously; refreshAwb / next sync picks it up
            }

            return ['ok' => true, 'error' => null];
        } catch (ShopeeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Honour an explicit method; "auto" prefers pickup (courier comes), then drop-off. */
    private function pickMethod(string $pref, array $needed): string
    {
        if (in_array($pref, ['pickup', 'dropoff', 'non_integrated'], true)) {
            return $pref;
        }

        return array_key_exists('pickup', $needed) ? 'pickup' : (array_key_exists('dropoff', $needed) ? 'dropoff' : 'pickup');
    }

    /** ship_order body for one method, filled with only the fields Shopee listed in info_needed. */
    private function methodBody(string $method, array $fields, array $param, array $item): object
    {
        if ($method === 'pickup') {
            return (object) $this->pickup($param['pickup']['address_list'] ?? [], $item['pickup_time_id'] ?? null);
        }
        $body = [];
        foreach ($fields as $field) {
            $value = match ($field) {
                'branch_id' => $item['branch_id'] ?? ($param['dropoff']['branch_list'][0]['branch_id'] ?? null),
                'slug' => $item['slug'] ?? ($param['dropoff']['slug_list'][0]['slug'] ?? null),
                'sender_real_name' => $item['sender_real_name'] ?? null,
                'tracking_no', 'tracking_number' => $item['tracking_number'] ?? null,
                default => null,
            };
            if ($value === null || $value === '') {
                throw new ShopeeException(match ($field) {
                    'branch_id' => 'pilih counter drop-off',
                    'sender_real_name' => 'isi nama pengirim untuk drop-off',
                    'tracking_no', 'tracking_number' => 'isi nomor resi dari ekspedisi',
                    default => "data {$field} wajib diisi",
                });
            }
            $body[$field === 'tracking_no' ? 'tracking_number' : $field] = $value;
        }

        return (object) $body;
    }

    private function pickup(array $addresses, ?string $timeId = null): array
    {
        if (! $addresses) {
            throw new ShopeeException('alamat pickup toko belum diatur di Shopee Seller Centre');
        }
        $addr = collect($addresses)->first(fn ($a) => in_array('pickup_address', $a['address_flag'] ?? [], true)) ?? $addresses[0];
        $slots = collect($addr['time_slot_list'] ?? []);
        $slot = $slots->first(fn ($t) => (string) $t['pickup_time_id'] === (string) $timeId) ?? $slots->first();

        return ['address_id' => $addr['address_id'], 'pickup_time_id' => $slot['pickup_time_id'] ?? null];
    }
}
