<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceEvent;
use App\Models\MarketplaceShop;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Http\Request;

/** Shopee Live Push receiver (set the Push URL in open.shopee.com → App → Push Mechanism). */
class ShopeePushController extends Controller
{
    public function __invoke(Request $request)
    {
        $url = config('services.shopee.push_url') ?: $request->url();
        abort_unless((new ShopeeClient)->verifyPush($url, $request->getContent(), $request->header('Authorization')), 401);

        $payload = $request->json()->all();
        $event = MarketplaceEvent::create([
            'platform' => 'shopee', 'shop_id' => $payload['shop_id'] ?? null, 'code' => (int) ($payload['code'] ?? 0), 'payload' => $payload,
        ]);

        try {
            $shops = MarketplaceShop::where('platform', 'shopee')->where('shop_id', $payload['shop_id'] ?? 0)->where('status', 'active')->where('is_demo', false)->get();
            $orderSn = $payload['data']['ordersn'] ?? $payload['data']['order_sn'] ?? null;
            foreach ($shops as $shop) {
                match ($event->code) {
                    // 3 = order status update, 4 = tracking number update
                    3, 4 => $orderSn ? (new ShopeeSync)->order($shop, $orderSn) : null,
                    // 2 = seller revoked the authorisation
                    2 => $shop->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null]),
                    default => null,
                };
            }
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            $event->update(['error' => mb_substr($e->getMessage(), 0, 250)]);
        }

        return response()->json(['ok' => true]);
    }
}
