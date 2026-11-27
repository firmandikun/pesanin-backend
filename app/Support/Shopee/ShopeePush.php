<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceEvent;
use App\Models\MarketplaceShop;

/**
 * Applies one Shopee Live Push event (order status, tracking number, booking, revoked authorisation).
 *
 * Events are stored either by ShopeePushController (processed right away) or by the Go receiver
 * (services/cmd/shopee-push, which only stores them and answers Shopee in milliseconds); the
 * `shopee:push-process` command then works through the stored ones. Every event is claimed with
 * one atomic UPDATE first, so the two paths can never apply the same event twice.
 */
class ShopeePush
{
    public function process(MarketplaceEvent $event): bool
    {
        $claimed = MarketplaceEvent::whereKey($event->id)->whereNull('processed_at')->whereNull('error')->update(['processed_at' => now()]);
        if (! $claimed) {
            return false;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        try {
            $shops = MarketplaceShop::where('platform', 'shopee')->where('shop_id', $payload['shop_id'] ?? 0)->where('status', 'active')->where('is_demo', false)->get();
            $orderSn = $payload['data']['ordersn'] ?? $payload['data']['order_sn'] ?? null;
            $bookingSn = $payload['data']['booking_sn'] ?? $payload['data']['bookingsn'] ?? null;
            foreach ($shops as $shop) {
                if ($bookingSn) {
                    (new ShopeeSync)->booking($shop, (string) $bookingSn);
                }
                match ((int) $event->code) {
                    // 3 = order status update, 4 = tracking number update
                    3, 4 => $orderSn ? (new ShopeeSync)->order($shop, $orderSn) : null,
                    // 2 = seller revoked the authorisation
                    2 => $shop->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null]),
                    default => $orderSn ? (new ShopeeSync)->order($shop, (string) $orderSn) : null,
                };
            }
            MarketplaceEvent::whereKey($event->id)->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            MarketplaceEvent::whereKey($event->id)->update(['error' => mb_substr($e->getMessage(), 0, 250)]);
        }

        return true;
    }

    /** Works through events stored but not yet applied (newest day only), oldest first, for at most $seconds. */
    public function drain(int $seconds = 50, int $limit = 500): int
    {
        $until = microtime(true) + $seconds;
        $done = 0;
        while (microtime(true) < $until && $done < $limit) {
            $batch = MarketplaceEvent::where('platform', 'shopee')->whereNull('processed_at')->whereNull('error')
                ->where('created_at', '>=', now()->subDay())->orderBy('id')->limit(50)->get();
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($batch as $event) {
                if (microtime(true) >= $until) {
                    break 2;
                }
                $done += $this->process($event) ? 1 : 0;
            }
        }

        return $done;
    }
}
