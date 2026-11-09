<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Order;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * New-order (and new Shopee chat) notifications: a row in the bell feed (polled by the app every few seconds) plus a
 * Web Push message to every browser the seller enabled, so it shows up even with the tab closed.
 * Web Push uses VAPID keys (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY), no Firebase project needed;
 * Chrome delivers it through FCM under the hood.
 */
class Notifier
{
    /** Set to true while seeding demo data so it doesn't flood the feed. */
    public static bool $muted = false;

    public static function newOrder(Order $order): void
    {
        if (self::$muted || $order->is_abandoned) {
            return;
        }
        // A first Shopee sync imports old orders too; only fresh ones are "new".
        if ($order->channel === 'shopee' && $order->created_at && $order->created_at->lt(now()->subHours(6))) {
            return;
        }
        $shopee = $order->channel === 'shopee';
        $title = $shopee ? 'Pesanan Shopee baru' : 'Pesanan baru';
        $body = trim(implode(' · ', array_filter([
            $shopee ? $order->external_order_sn : $order->code,
            $shopee ? $order->marketplaceShop?->shop_name : $order->customer_name,
            'Rp'.number_format((float) $order->total, 0, ',', '.'),
        ])));
        self::send($order->user_id, 'order', $title, $body, $shopee ? '/marketplace/orders' : '/orders', $order->id);
    }

    public static function send(int $userId, string $type, string $title, ?string $body, ?string $url, ?int $orderId = null): AppNotification
    {
        $n = AppNotification::create(['user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'url' => $url, 'order_id' => $orderId]);
        if (app()->runningInConsole()) {
            self::push($n);
        } else {
            dispatch(fn () => self::push($n))->afterResponse();
        }

        return $n;
    }

    public static function vapidReady(): bool
    {
        return (bool) (config('services.webpush.public_key') && config('services.webpush.private_key'));
    }

    private static function push(AppNotification $n): void
    {
        $subs = PushSubscription::where('user_id', $n->user_id)->get();
        if ($subs->isEmpty() || ! self::vapidReady()) {
            return;
        }
        try {
            $wp = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 3600, 'urgency' => 'high']);
            $payload = json_encode(['id' => $n->id, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url, 'tag' => $n->order_id ? 'order-'.$n->order_id : $n->type.'-'.$n->id]);
            foreach ($subs as $s) {
                $wp->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth]), $payload);
            }
            foreach ($wp->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                }
            }
        } catch (Throwable $e) {
            Log::warning('Web push failed: '.$e->getMessage());
        }
    }
}
