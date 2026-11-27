<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceEvent;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeePush;
use Illuminate\Http\Request;

/**
 * Shopee Live Push receiver (set the Push URL in open.shopee.com → App → Push Mechanism).
 * In production the gateway can route /api/shopee/push to the Go receiver (services/cmd/shopee-push) instead,
 * which stores the event and lets `shopee:push-process` apply it; this controller stays as the fallback.
 */
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
        (new ShopeePush)->process($event);

        return response()->json(['ok' => true]);
    }
}
