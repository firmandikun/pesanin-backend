<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceShop;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
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
            'orders' => $user->orders()->whereIn('id', $ids)->with(['product:id,name,sku,weight', 'marketplaceShop:id,shop_name,is_demo'])->get(),
        ];
    }

    /**
     * What each selected Shopee order supports before arranging shipment, straight from Shopee's
     * get_shipping_parameter: `info_needed` lists the methods the courier + shop allow
     * (pickup, dropoff, non_integrated) and the fields each one needs. Many couriers, or shops
     * that turned pickup off, only allow drop-off; instant couriers only allow pickup.
     */
    public function shopeeOptions(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $api = new ShopeeClient;
        $out = [];
        foreach ($this->shopeeOrders($request, $ids) as $order) {
            $row = [
                'id' => $order->id, 'code' => $order->external_order_sn, 'courier' => $order->courier,
                'shop' => $order->marketplaceShop->shop_name, 'methods' => [], 'needs' => [],
                'slots' => [], 'address' => null, 'branches' => [], 'slugs' => [], 'error' => null,
            ];
            if ($order->external_status !== 'READY_TO_SHIP') {
                $row['error'] = 'Pesanan ini sudah diatur pengirimannya.';
            } elseif ($order->marketplaceShop->is_demo) {
                $row['methods'] = ['pickup', 'dropoff'];
                $row['needs'] = ['pickup' => [], 'dropoff' => []];
            } else {
                try {
                    $p = $api->shippingParameter($order->marketplaceShop, $order->external_order_sn);
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
                } catch (ShopeeException $e) {
                    $row['error'] = $e->getMessage();
                }
            }
            $out[] = $row;
        }

        return ['orders' => $out, 'sender_name' => $request->user()->store_name ?: $request->user()->name];
    }

    /**
     * Arrange shipment for READY_TO_SHIP orders. Each item carries its own method (chosen per
     * courier in the modal) plus the fields Shopee asked for; then tracking numbers are fetched.
     */
    public function shopeeShip(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.order_id' => 'required|integer',
            'items.*.method' => 'required|in:pickup,dropoff,non_integrated',
            'items.*.pickup_time_id' => 'nullable|string|max:100',
            'items.*.branch_id' => 'nullable|integer',
            'items.*.slug' => 'nullable|string|max:100',
            'items.*.sender_real_name' => 'nullable|string|max:100',
            'items.*.tracking_number' => 'nullable|string|max:100',
        ]);
        $items = collect($data['items'])->keyBy('order_id');
        $api = new ShopeeClient;
        $done = [];
        $failed = [];
        foreach ($this->shopeeOrders($request, $items->keys()->all()) as $order) {
            $item = $items[$order->id];
            if ($order->external_status !== 'READY_TO_SHIP') {
                $failed[] = ['code' => $order->code, 'error' => 'bukan pesanan siap kirim ('.$order->external_status.')'];
                continue;
            }
            // Sample shop: simulate what Shopee does (PROCESSED + tracking number issued).
            if ($order->marketplaceShop->is_demo) {
                $order->update(['external_status' => 'PROCESSED', 'tracking_number' => 'SPXID0'.random_int(40000000, 49999999)]);
                $done[] = $order->code;
                continue;
            }
            try {
                $shop = $order->marketplaceShop;
                $param = $api->shippingParameter($shop, $order->external_order_sn);
                $needed = $param['info_needed'] ?? [];
                $use = $item['method'];
                if (! array_key_exists($use, $needed)) {
                    throw new ShopeeException(match ($use) {
                        'pickup' => 'ekspedisi ini tidak bisa dijemput, pilih drop-off',
                        'dropoff' => 'ekspedisi ini tidak bisa drop-off, pilih pickup',
                        default => 'metode pengiriman tidak tersedia',
                    });
                }
                $api->shipOrder($shop, $order->external_order_sn, [$use => $this->methodBody($use, (array) $needed[$use], $param, $item)]);
                ShopeeSync::markArranged($order);
                try {
                    $order->update(['tracking_number' => $api->trackingNumber($shop, $order->external_order_sn)]);
                } catch (ShopeeException) {
                    // issued asynchronously; the next sync picks it up
                }
                (new ShopeeSync)->order($shop, $order->external_order_sn);
                $done[] = $order->code;
            } catch (ShopeeException $e) {
                $failed[] = ['code' => $order->code, 'error' => $e->getMessage()];
            }
        }

        return ['shipped' => $done, 'failed' => $failed];
    }

    /** Fetch tracking numbers Shopee issued after ship_order (they arrive a few seconds later). */
    public function shopeeRefresh(Request $request)
    {
        $data = $request->validate(['order_ids' => 'required|array|min:1|max:50', 'order_ids.*' => 'integer']);
        $api = new ShopeeClient;
        $ready = 0;
        foreach ($this->shopeeOrders($request, $data['order_ids']) as $order) {
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

        return ['ready' => $ready, 'total' => count($data['order_ids'])];
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

    /** Shopee AWB PDF for orders of one shop. */
    public function shopeePdf(Request $request)
    {
        $data = $request->validate(['order_ids' => 'required|array|min:1|max:50', 'order_ids.*' => 'integer']);
        $orders = $this->shopeeOrders($request, $data['order_ids']);
        abort_if($orders->isEmpty(), 422, 'Pilih pesanan Shopee.');
        $locked = $orders->filter(fn ($o) => ! in_array($o->external_status, ['PROCESSED', 'READY_TO_SHIP', 'RETRY_SHIP'], true));
        abort_if($locked->isNotEmpty(), 422, 'Resi tidak bisa dicetak lagi untuk pesanan yang sudah dalam pengiriman: '.$locked->pluck('external_order_sn')->implode(', '));
        abort_if($orders->pluck('marketplace_shop_id')->unique()->count() > 1, 422, 'Cetak resi Shopee per toko: pilih pesanan dari satu toko saja.');
        // Shopee only batches air waybills of the same logistics channel.
        abort_if($orders->pluck('courier')->unique()->count() > 1, 422, 'Shopee hanya bisa menggabungkan resi dari ekspedisi yang sama. Unduh per ekspedisi.');
        $shop = $orders->first()->marketplaceShop;
        abort_if($shop->is_demo, 422, 'Toko contoh tidak punya resi Shopee. Hubungkan toko asli untuk mencetak resi.');

        $api = new ShopeeClient;
        $sync = new ShopeeSync;
        // Tracking numbers are issued after the shipment is arranged; refresh any that are still missing.
        foreach ($orders->whereNull('tracking_number') as $o) {
            $o->tracking_number = $sync->order($shop, $o->external_order_sn)?->tracking_number;
        }
        abort_if($orders->contains(fn ($o) => ! $o->tracking_number), 422, 'Sebagian pesanan belum punya nomor resi. Atur pengiriman dulu, lalu coba lagi dalam beberapa saat.');
        $sns = $orders->pluck('external_order_sn')->all();
        try {
            $api->createShippingDocument($shop, $orders->pluck('tracking_number', 'external_order_sn')->all());
            for ($i = 0; $i < 6; $i++) {
                $pending = collect($api->shippingDocumentResult($shop, $sns))->where('status', 'PROCESSING')->count();
                if (! $pending) {
                    break;
                }
                usleep(800_000);
            }
            $pdf = $api->downloadShippingDocument($shop, $sns);
        } catch (ShopeeException $e) {
            abort(422, 'Resi Shopee belum bisa dicetak: '.$e->getMessage().'. Pastikan pesanan sudah diatur pengirimannya.');
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="resi-shopee.pdf"']);
    }

    private function shopeeOrders(Request $request, array $ids)
    {
        return $request->user()->orders()->whereIn('id', $ids)->where('channel', 'shopee')->whereNotNull('external_order_sn')->with('marketplaceShop')->get();
    }

    /** Address flagged for pickup, with the chosen time slot when it exists there, else the earliest one. */
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
