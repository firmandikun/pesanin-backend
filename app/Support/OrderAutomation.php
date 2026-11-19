<?php

namespace App\Support;

use App\Http\Controllers\Api\WhatsAppChatController;
use App\Models\Order;
use App\Models\User;
use App\Models\WaMessage;
use App\Support\WhatsApp\WhatsAppCloud;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs every 30 seconds (orders:automate):
 *  - digital products: once paid, the buyer gets their access link by WhatsApp template and/or email,
 *    and the order is completed.
 * Seller switches live in user settings "automation".
 */
class OrderAutomation
{
    public const MAX_TRIES = 3;

    /** Only freshly paid orders are automated, so switching it on never touches an old backlog. */
    public const RECENT_DAYS = 3;

    public static function settings(User $user): array
    {
        return ($user->settings['automation'] ?? []) + [
            'auto_ship' => false, 'auto_ship_cod' => false,
            'auto_ship_shopee' => false, 'auto_ship_shopee_method' => 'auto',
            'awb_schedule' => false, 'awb_times' => ['08:00'], 'awb_slots' => null,
            'digital_email' => true, 'digital_wa_template' => null,
        ];
    }

    public static function run(): array
    {
        return ['shipped' => \App\Support\RajaOngkir\CourierBooking::auto(), 'shopee_shipped' => self::shopeeAutoShip(), 'delivered' => self::deliverDigital(),
            // Stok master: push changed stock to every linked Shopee listing.
            'stock_pushed' => \App\Support\MasterStock::pushPending()];
    }

    /**
     * Re-read the orders from Shopee right before arranging them and keep only those still READY_TO_SHIP, so an order
     * Shopee holds as "Tertunda" (PENDING, under review) is moved to its tab instead of being arranged. Covers orders
     * stored before PENDING was requested and ones Shopee puts on hold after they came in. A failed read keeps the
     * list as is (arranging then fails safely and is retried).
     */
    private static function freshFromShopee(\Illuminate\Support\Collection $orders): \Illuminate\Support\Collection
    {
        $api = new \App\Support\Shopee\ShopeeClient;
        $sync = new \App\Support\Shopee\ShopeeSync;
        foreach ($orders->groupBy('marketplace_shop_id') as $group) {
            $shop = $group->first()->marketplaceShop;
            try {
                foreach ($group->chunk(50) as $chunk) {
                    foreach ($api->orderDetail($shop, $chunk->pluck('external_order_sn')->all()) as $d) {
                        $sync->upsertOrder($shop, $d);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $orders->map->fresh()->filter(fn ($o) => $o && $o->external_status === 'READY_TO_SHIP' && ! $o->shopee_auto_ship)->values();
    }

    /**
     * Auto "Atur Pengiriman" for Shopee: every READY_TO_SHIP order (not Advance Fulfilment, which Shopee ships
     * itself) is arranged in the background so the seller need not open the web. Method per the seller's setting
     * ("auto" = jemput kurir, fallback drop-off). Failures are retried up to MAX_TRIES, then the seller is notified.
     */
    public static function shopeeAutoShip(): int
    {
        $done = 0;
        $users = User::whereHas('marketplaceShops', fn ($q) => $q->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false))->get()
            ->filter(fn ($u) => self::settings($u)['auto_ship_shopee']);
        foreach ($users as $user) {
            $pref = self::settings($user)['auto_ship_shopee_method'] ?: 'auto';
            $sender = $user->store_name ?: $user->name;
            $shipper = new \App\Support\Shopee\ShopeeShipper;
            $orders = $user->orders()->active()->where('channel', 'shopee')
                ->where('external_status', 'READY_TO_SHIP')->where('shopee_auto_ship', false)
                ->where('auto_ship_tries', '<', self::MAX_TRIES)
                ->whereHas('marketplaceShop', fn ($q) => $q->where('is_demo', false))
                ->whereRaw('COALESCE(paid_at, created_at) >= ?', [now()->subDays(self::RECENT_DAYS)])
                ->orderBy('id')->limit(30)->get();
            $orders = self::freshFromShopee($orders);
            foreach ($orders as $order) {
                $r = $shipper->arrange($order, ['method' => $pref, 'sender_real_name' => $sender]);
                if ($r['ok']) {
                    $order->update(['auto_ship_error' => null]);
                    $done++;
                    continue;
                }
                $tries = $order->auto_ship_tries + 1;
                $order->update(['auto_ship_tries' => $tries, 'auto_ship_error' => mb_substr((string) $r['error'], 0, 300)]);
                if ($tries >= self::MAX_TRIES) {
                    Notifier::send($user->id, 'order', 'Atur pengiriman otomatis gagal', "{$order->code}: {$r['error']} Atur manual dari Pesanan Shopee.", '/marketplace/orders', $order->id);
                }
            }
        }

        return $done;
    }

    public static function deliverDigital(): int
    {
        $n = 0;
        $orders = Order::active()->with(['product', 'user'])
            ->whereHas('product', fn ($q) => $q->where('type', 'digital'))
            ->where('payment_status', 'paid')->whereNull('digital_sent_at')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereRaw('COALESCE(paid_at, updated_at) >= ?', [now()->subDays(self::RECENT_DAYS)])
            ->orderBy('id')->limit(50)->get();
        foreach ($orders as $order) {
            self::deliver($order);
            $n++;
        }

        return $n;
    }

    public static function accessUrl(Order $order): string
    {
        if (! $order->digital_token) {
            $order->forceFill(['digital_token' => Str::random(40)])->save();
        }

        return rtrim(config('services.frontend_url'), '/').'/d/'.$order->digital_token;
    }

    /** Sends the access link (WhatsApp template and/or email), then completes the order. */
    public static function deliver(Order $order): array
    {
        $url = self::accessUrl($order);
        $s = self::settings($order->user);
        $sent = [];
        $errors = [];

        $tpl = $s['digital_wa_template'] ?? null;
        $wa = WhatsAppCloud::for(WhatsAppChatController::integration($order->user));
        if ($tpl && $wa && $order->phone) {
            $params = array_slice([$order->customer_name ?: 'Kak', $url, $order->product?->name ?? 'produk'], 0, (int) ($tpl['variables'] ?? 2));
            try {
                $to = \App\Models\Customer::normalizePhone($order->phone);
                $res = $wa->sendTemplate($to, $tpl['name'], $tpl['language'] ?? 'id', $params);
                if ($mid = $res['messages'][0]['id'] ?? null) {
                    WaMessage::firstOrCreate(['message_id' => $mid], [
                        'user_id' => $order->user_id, 'phone_number_id' => WhatsAppChatController::integration($order->user)->settings['phone_number_id'],
                        'wa_id' => $res['contacts'][0]['wa_id'] ?? $to, 'contact_name' => $order->customer_name, 'direction' => 'out', 'type' => 'template',
                        'text' => "[Produk digital] {$order->product?->name}: {$url}", 'status' => 'sent', 'read' => true, 'sent_at' => now(),
                    ]);
                }
                $sent[] = 'WhatsApp';
            } catch (Throwable $e) {
                $errors[] = 'WhatsApp: '.$e->getMessage();
            }
        }

        if (($s['digital_email'] ?? true) && $order->email) {
            try {
                $store = $order->user->store_name;
                $product = $order->product?->name ?? 'produk digital';
                Mail::raw("Halo {$order->customer_name},\n\nTerima kasih, pembayaran pesanan {$order->code} sudah kami terima.\n\nAkses {$product} di link berikut:\n{$url}\n\nSimpan link ini agar bisa dibuka lagi kapan saja.\n\nSalam,\n{$store}",
                    fn ($m) => $m->to($order->email, $order->customer_name)
                        // Keep the verified sending address (Sumopod checks it), show the store as the name.
                        ->from(config('mail.from.address'), $store ?: config('mail.from.name'))
                        ->replyTo($order->user->email ?: config('mail.from.address'), $store ?: null)
                        ->subject("Akses {$product} – {$store}"));
                $sent[] = 'email';
            } catch (Throwable $e) {
                $errors[] = 'Email: '.$e->getMessage();
            }
        }

        $order->forceFill([
            'digital_sent_at' => now(), 'digital_error' => $errors ? mb_substr(implode(' · ', $errors), 0, 300) : null,
            'status' => in_array($order->status, ['pending', 'processing'], true) ? 'completed' : $order->status,
            'completed_at' => $order->completed_at ?? now(),
        ])->save();
        Notifier::send($order->user_id, 'order', $sent ? 'Produk digital terkirim' : 'Produk digital siap diakses',
            $order->code.': '.($sent ? 'dikirim lewat '.implode(' & ', $sent) : 'kirim link akses ke pembeli').($errors ? ' · '.implode(' · ', $errors) : ''), '/orders?search='.$order->code, $order->id);
        if ($errors) {
            Log::warning("Digital delivery {$order->code}: ".implode(' · ', $errors));
        }

        return ['url' => $url, 'sent' => $sent, 'errors' => $errors];
    }
}
