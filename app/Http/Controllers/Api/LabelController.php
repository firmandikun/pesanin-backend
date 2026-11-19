<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceShop;
use App\Support\Shopee\ShopeeAwb;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeeShipper;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Http\Request;

/** Shipping labels: Shopee air waybills (PDF from Shopee) and Pesanin labels for website/manual orders. */
class LabelController extends Controller
{
    /** Data for printing Pesanin's own labels (website, landing page and manual orders). */
    public function data(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $user = $request->user();
        $sender = $user->pickupAddress();

        return [
            'sender' => [
                'name' => $user->store_name, 'phone' => $sender?->phone ?? $user->whatsapp,
                'address' => $sender ? trim($sender->address.', '.($sender->region?->district ?? '').', '.($sender->region?->city ?? '')) : null,
            ],
            // Over-quota orders are left out until the seller upgrades (no buyer address to print).
            'orders' => $user->orders()->whereIn('id', $ids)->with(['product:id,name,sku,weight', 'marketplaceShop:id,shop_name,is_demo'])->get()
                ->reject(fn ($o) => \App\Support\OrderQuota::locked($o, $user))->values(),
        ];
    }

    /**
     * What each selected Shopee order or Pengiriman Kilat reservation supports before arranging shipment,
     * straight from Shopee's get_shipping_parameter / get_booking_shipping_parameter: `info_needed` lists
     * the methods the courier + shop allow (pickup, dropoff, non_integrated) and the fields each one needs.
     */
    public function shopeeOptions(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $bookingIds = array_filter(array_map('intval', explode(',', (string) $request->query('booking_ids'))));
        $api = new ShopeeClient;
        $out = [];
        foreach ($this->shopeeOrders($request, $ids) as $order) {
            $row = [
                'kind' => 'order', 'id' => $order->id, 'order_id' => $order->id, 'booking_id' => null,
                'code' => $order->external_order_sn, 'courier' => $order->courier,
                'shop' => $order->marketplaceShop->shop_name, 'methods' => [], 'needs' => [],
                'slots' => [], 'address' => null, 'branches' => [], 'slugs' => [], 'error' => null,
            ];
            if (! in_array($order->external_status, ['READY_TO_SHIP', 'IN_CANCEL'], true)) {
                $row['error'] = 'Pesanan ini sudah diatur pengirimannya.';
            } elseif ($order->marketplaceShop->is_demo) {
                $row['methods'] = ['pickup', 'dropoff'];
                $row['needs'] = ['pickup' => [], 'dropoff' => []];
            } else {
                try {
                    $this->fillShippingParam($row, $api->shippingParameter($order->marketplaceShop, $order->external_order_sn));
                } catch (ShopeeException $e) {
                    $row['error'] = $e->getMessage();
                }
            }
            $out[] = $row;
        }
        foreach ($this->shopeeBookings($request, $bookingIds) as $booking) {
            $row = [
                'kind' => 'booking', 'id' => $booking->id, 'order_id' => null, 'booking_id' => $booking->id,
                'code' => $booking->booking_sn, 'courier' => $booking->shipping_carrier,
                'shop' => $booking->marketplaceShop->shop_name, 'methods' => [], 'needs' => [],
                'slots' => [], 'address' => null, 'branches' => [], 'slugs' => [], 'error' => null,
            ];
            if ($booking->booking_status !== 'READY_TO_SHIP') {
                $row['error'] = 'Reservasi ini sudah diatur pengirimannya.';
            } elseif ($booking->marketplaceShop->is_demo) {
                $row['methods'] = ['pickup', 'dropoff'];
                $row['needs'] = ['pickup' => [], 'dropoff' => []];
            } else {
                try {
                    $this->fillShippingParam($row, $api->bookingShippingParameter($booking->marketplaceShop, $booking->booking_sn));
                } catch (ShopeeException $e) {
                    $row['error'] = $e->getMessage();
                }
            }
            $out[] = $row;
        }

        return ['orders' => $out, 'sender_name' => $request->user()->store_name ?: $request->user()->name];
    }

    private function fillShippingParam(array &$row, array $p): void
    {
        $needed = $p['info_needed'] ?? [];
        $row['methods'] = array_values(array_intersect(['pickup', 'dropoff', 'non_integrated'], array_keys($needed)));
        $row['needs'] = array_map(fn ($f) => array_values((array) $f), $needed);
        $addr = collect($p['pickup']['address_list'] ?? [])->first(fn ($a) => in_array('pickup_address', $a['address_flag'] ?? [], true)) ?? ($p['pickup']['address_list'][0] ?? null);
        if ($addr) {
            $row['address'] = trim(($addr['address'] ?? '').', '.($addr['city'] ?? ''), ', ');
            $row['slots'] = collect($addr['time_slot_list'] ?? [])->map(fn ($t) => [
                'id' => (string) $t['pickup_time_id'], 'date' => $t['date'] ?? null, 'text' => $t['time_text'] ?? null,
            ])->values();
        }
        $row['branches'] = collect($p['dropoff']['branch_list'] ?? [])->map(fn ($b) => [
            'id' => $b['branch_id'],
            'name' => trim(implode(', ', array_filter([$b['address'] ?? null, $b['district'] ?? null, $b['city'] ?? null]))),
        ])->values();
        $row['slugs'] = collect($p['dropoff']['slug_list'] ?? [])->map(fn ($s) => ['id' => $s['slug'], 'name' => $s['slug_name'] ?? $s['slug']])->values();
    }

    /**
     * Arrange shipment for READY_TO_SHIP orders and/or Pengiriman Kilat reservations.
     * Each item carries its own method (chosen per courier in the modal) plus the fields Shopee asked for.
     */
    public function shopeeShip(Request $request)
    {
        if ($request->has('order_ids') && ! $request->has('items')) {
            $legacy = $request->validate([
                'order_ids' => 'required|array|min:1|max:50',
                'order_ids.*' => 'integer',
                'method' => 'required|in:pickup,dropoff,non_integrated',
            ]);
            $request->merge([
                'items' => array_map(fn ($id) => ['order_id' => $id, 'method' => $legacy['method']], $legacy['order_ids']),
            ]);
        }
        $data = $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.order_id' => 'nullable|integer|required_without:items.*.booking_id',
            'items.*.booking_id' => 'nullable|integer|required_without:items.*.order_id',
            'items.*.method' => 'required|in:pickup,dropoff,non_integrated',
            'items.*.pickup_time_id' => 'nullable|string|max:100',
            'items.*.branch_id' => 'nullable|integer',
            'items.*.slug' => 'nullable|string|max:100',
            'items.*.sender_real_name' => 'nullable|string|max:100',
            'items.*.tracking_number' => 'nullable|string|max:100',
        ]);
        $orderItems = collect($data['items'])->filter(fn ($i) => ! empty($i['order_id']))->keyBy('order_id');
        $bookingItems = collect($data['items'])->filter(fn ($i) => ! empty($i['booking_id']))->keyBy('booking_id');
        $shipper = new ShopeeShipper;
        $done = [];
        $failed = [];
        foreach ($this->shopeeOrders($request, $orderItems->keys()->all()) as $order) {
            $r = $shipper->arrange($order, $orderItems[$order->id]);
            $r['ok'] ? $done[] = $order->code : $failed[] = ['code' => $order->code, 'error' => $r['error']];
        }
        foreach ($this->shopeeBookings($request, $bookingItems->keys()->all()) as $booking) {
            $r = $shipper->arrangeBooking($booking, $bookingItems[$booking->id]);
            $r['ok'] ? $done[] = $booking->booking_sn : $failed[] = ['code' => $booking->booking_sn, 'error' => $r['error']];
        }

        return ['shipped' => $done, 'failed' => $failed];
    }

    /**
     * Respond to a buyer's cancellation request (IN_CANCEL) on Shopee: REJECT (tolak) or ACCEPT (terima).
     */
    public function shopeeCancel(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|integer',
            'operation' => 'nullable|in:REJECT,ACCEPT',
        ]);
        $op = strtoupper($data['operation'] ?? 'REJECT');
        $order = $this->shopeeOrders($request, [$data['order_id']])->first();
        abort_unless((bool) $order, 404, 'Pesanan Shopee tidak ditemukan.');
        abort_unless($order->external_status === 'IN_CANCEL', 422, 'Pesanan ini tidak sedang dalam pengajuan pembatalan.');

        $shop = $order->marketplaceShop;
        if (! $shop->is_demo) {
            try {
                (new ShopeeClient)->handleBuyerCancellation($shop, $order->external_order_sn, $op);
            } catch (ShopeeException $e) {
                abort(422, 'Gagal memproses pembatalan di Shopee: '.$e->getMessage());
            }
        }

        if ($op === 'REJECT') {
            ShopeeSync::markCancelRejected($order);
        } else {
            $order->update(['external_status' => 'CANCELLED', 'status' => 'cancelled']);
        }

        if (! $shop->is_demo) {
            try {
                (new ShopeeSync)->order($shop, $order->external_order_sn);
            } catch (ShopeeException) {
                // Keep the optimistic status update above; the next scheduled sync will reconcile.
            }
        }

        \App\Models\ActivityLog::record(
            $request->user(),
            'order.shopee_cancel',
            ($op === 'REJECT' ? 'Menolak' : 'Menyetujui')." pengajuan pembatalan pesanan Shopee {$order->external_order_sn}"
        );

        return [
            'order' => $order->fresh(['marketplaceShop']),
            'message' => $op === 'REJECT'
                ? "Pengajuan pembatalan pesanan {$order->external_order_sn} berhasil ditolak."
                : "Pengajuan pembatalan pesanan {$order->external_order_sn} disetujui.",
        ];
    }

    /** Fetch tracking numbers Shopee issued after ship_order / ship_booking (they arrive a few seconds later). */
    public function shopeeRefresh(Request $request)
    {
        $data = $request->validate([
            'order_ids' => 'nullable|array|max:50',
            'order_ids.*' => 'integer',
            'booking_ids' => 'nullable|array|max:50',
            'booking_ids.*' => 'integer',
        ]);
        $orderIds = $data['order_ids'] ?? [];
        $bookingIds = $data['booking_ids'] ?? [];
        $api = new ShopeeClient;
        $ready = 0;
        foreach ($this->shopeeOrders($request, $orderIds) as $order) {
            if (! $order->tracking_number && ! $order->marketplaceShop->is_demo) {
                try {
                    (new ShopeeSync)->order($order->marketplaceShop, $order->external_order_sn);
                    $order->refresh();
                    if (! $order->tracking_number && $tn = $api->trackingNumber($order->marketplaceShop, $order->external_order_sn)) {
                        $order->update(['tracking_number' => $tn]);
                    }
                } catch (ShopeeException) {
                    // try again on the next refresh or scheduled sync
                }
            }
            $ready += $order->tracking_number ? 1 : 0;
        }
        foreach ($this->shopeeBookings($request, $bookingIds) as $booking) {
            if (! $booking->tracking_number && ! $booking->marketplaceShop->is_demo) {
                try {
                    (new ShopeeSync)->booking($booking->marketplaceShop, $booking->booking_sn);
                    $booking->refresh();
                    if (! $booking->tracking_number && $tn = $api->bookingTrackingNumber($booking->marketplaceShop, $booking->booking_sn)) {
                        $booking->update(['tracking_number' => $tn]);
                    }
                } catch (ShopeeException) {
                    // try again on the next refresh or scheduled sync
                }
            }
            $ready += $booking->tracking_number ? 1 : 0;
        }

        return ['ready' => $ready, 'total' => count($orderIds) + count($bookingIds)];
    }

    /** Shopee AWB PDF for orders of one shop. */
    public function shopeePdf(Request $request)
    {
        $data = $request->validate(['order_ids' => 'required|array|min:1|max:50', 'order_ids.*' => 'integer']);
        $orders = $this->shopeeOrders($request, $data['order_ids']);
        abort_if($orders->isEmpty(), 422, 'Pilih pesanan Shopee.');
        abort_if($orders->pluck('marketplace_shop_id')->unique()->count() > 1, 422, 'Cetak resi Shopee per toko: pilih pesanan dari satu toko saja.');
        // Shopee only batches air waybills of the same logistics channel.
        abort_if($orders->pluck('courier')->unique()->count() > 1, 422, 'Shopee hanya bisa menggabungkan resi dari ekspedisi yang sama. Unduh per ekspedisi.');
        $shop = $orders->first()->marketplaceShop;
        abort_if($shop->is_demo, 422, 'Toko contoh tidak punya resi Shopee. Hubungkan toko asli untuk mencetak resi.');

        $api = new ShopeeClient;
        $sync = new ShopeeSync;
        // Re-read the status from Shopee first: parcels already handed over are skipped (their label can't be printed
        // again) and move to "Dikirim"; the rest still print instead of failing the whole batch.
        try {
            foreach ($api->orderDetail($shop, $orders->pluck('external_order_sn')->all()) as $d) {
                $sync->upsertOrder($shop, $d);
            }
            $orders = $orders->map(fn ($o) => $o->fresh(['marketplaceShop']));
        } catch (ShopeeException) {
            // Shopee unreachable: decide on the stored status.
        }
        $locked = $orders->filter(fn ($o) => ! in_array($o->external_status, ['PROCESSED', 'READY_TO_SHIP', 'RETRY_SHIP', 'IN_CANCEL'], true));
        $orders = $orders->reject(fn ($o) => $locked->contains('id', $o->id))->values();
        abort_if($orders->isEmpty(), 422, 'Semua pesanan terpilih sudah dalam pengiriman, jadi resinya tidak dicetak lagi (dipindah ke tab Dikirim): '.$locked->pluck('external_order_sn')->implode(', '));
        // Tracking numbers are issued after the shipment is arranged; refresh any that are still missing.
        foreach ($orders->whereNull('tracking_number') as $o) {
            $o->tracking_number = $sync->order($shop, $o->external_order_sn)?->tracking_number;
        }
        abort_if($orders->contains(fn ($o) => ! $o->tracking_number), 422, 'Sebagian pesanan belum punya nomor resi. Atur pengiriman dulu, lalu coba lagi dalam beberapa saat.');
        try {
            $pdf = (new ShopeeAwb($api))->orders($shop, $orders);
        } catch (ShopeeException $e) {
            abort(422, 'Resi Shopee belum bisa dicetak: '.$e->getMessage().'. Pastikan pesanan sudah diatur pengirimannya.');
        }
        // Printed now: the scheduled Telegram send leaves these out.
        \App\Models\Order::whereIn('id', $orders->pluck('id'))->update(['awb_printed_at' => now()]);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="resi-shopee.pdf"',
            'X-Awb-Skipped' => $locked->pluck('external_order_sn')->implode(',')]);
    }

    /** Air waybills for "Pengiriman Kilat" reservations (Shopee bookings), same flow as orders. */
    public function shopeeBookingPdf(Request $request)
    {
        $data = $request->validate(['booking_ids' => 'required|array|min:1|max:50', 'booking_ids.*' => 'integer']);
        $bookings = $this->shopeeBookings($request, $data['booking_ids']);
        abort_if($bookings->isEmpty(), 422, 'Pilih reservasi Pengiriman Kilat.');
        abort_if($bookings->pluck('marketplace_shop_id')->unique()->count() > 1, 422, 'Cetak resi per toko: pilih reservasi dari satu toko saja.');
        abort_if($bookings->pluck('shipping_carrier')->unique()->count() > 1, 422, 'Shopee hanya bisa menggabungkan resi dari ekspedisi yang sama. Unduh per ekspedisi.');
        $shop = $bookings->first()->marketplaceShop;

        $api = new ShopeeClient;
        // Same as orders: refresh from Shopee, skip reservations that moved on (shipped/cancelled), print the rest.
        try {
            $sync = new ShopeeSync;
            foreach ($api->bookingDetail($shop, $bookings->pluck('booking_sn')->all()) as $d) {
                $sync->upsertBooking($shop, $d);
            }
            $bookings = $bookings->map(fn ($b) => $b->fresh(['marketplaceShop']));
        } catch (ShopeeException) {
        }
        $notReady = $bookings->where('booking_status', '!=', 'PROCESSED');
        $bookings = $bookings->reject(fn ($b) => $notReady->contains('id', $b->id))->values();
        abort_if($bookings->isEmpty(), 422, 'Semua reservasi terpilih sudah tidak bisa dicetak (sudah dikirim atau belum diproses): '.$notReady->pluck('booking_sn')->implode(', '));
        foreach ($bookings->whereNull('tracking_number') as $b) {
            try {
                $b->update(['tracking_number' => $api->bookingTrackingNumber($shop, $b->booking_sn) ?: null]);
            } catch (ShopeeException) {
            }
        }
        abort_if($bookings->contains(fn ($b) => ! $b->tracking_number), 422, 'Sebagian reservasi belum punya nomor resi dari Shopee. Coba lagi dalam beberapa saat.');
        try {
            $pdf = (new ShopeeAwb($api))->bookings($shop, $bookings);
        } catch (ShopeeException $e) {
            abort(422, 'Resi Pengiriman Kilat belum bisa dicetak: '.$e->getMessage());
        }
        \App\Models\ShopeeBooking::whereIn('id', $bookings->pluck('id'))->update(['awb_printed_at' => now()]);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="resi-kilat.pdf"',
            'X-Awb-Skipped' => $notReady->pluck('booking_sn')->implode(',')]);
    }

    private function shopeeOrders(Request $request, array $ids)
    {
        if (! $ids) {
            return collect();
        }

        return $request->user()->orders()->whereIn('id', $ids)->where('channel', 'shopee')->whereNotNull('external_order_sn')->with('marketplaceShop')->get();
    }

    private function shopeeBookings(Request $request, array $ids)
    {
        if (! $ids) {
            return collect();
        }

        return \App\Models\ShopeeBooking::where('user_id', $request->user()->id)->whereIn('id', $ids)->with('marketplaceShop')->get();
    }

    /** Address flagged for pickup, with the chosen time slot when it exists there, else the earliest one. */
}
