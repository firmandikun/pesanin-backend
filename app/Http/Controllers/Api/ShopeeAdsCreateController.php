<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\ShopeeAdCampaign;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeeSync;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Create Shopee Product Ads directly via Shopee Open Platform v2.ads.create_manual_product_ads.
 */
class ShopeeAdsCreateController extends Controller
{
    public function options(Request $request)
    {
        $user = $request->user();
        $shops = MarketplaceShop::where('user_id', $user->id)
            ->where('platform', 'shopee')
            ->where('status', 'active')
            ->where('is_demo', false)
            ->get(['id', 'shop_name']);

        if ($shops->isEmpty()) {
            return ['shops' => [], 'products' => []];
        }

        $shopId = $request->integer('shop_id') ?: $shops->first()->id;
        $shop = $shops->firstWhere('id', $shopId) ?? $shops->first();

        $recommendedIds = Cache::remember("shopee:ads_rec:{$shop->id}", 300, function () use ($shop) {
            try {
                return collect((new ShopeeClient)->adsRecommendedItems($shop))
                    ->pluck('item_id')->map(fn ($id) => (int) $id)->filter()->values()->all();
            } catch (Throwable) {
                return [];
            }
        });
        $recSet = array_flip($recommendedIds);

        $advertisedIds = ShopeeAdCampaign::where('marketplace_shop_id', $shop->id)
            ->whereIn('status', ['ongoing', 'scheduled'])
            ->get(['item_ids'])
            ->flatMap(fn ($c) => $c->item_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->flip()
            ->all();

        $listings = MarketplaceListing::where('user_id', $user->id)
            ->where('marketplace_shop_id', $shop->id)
            ->where('status', 'NORMAL')
            ->get(['id', 'item_id', 'name', 'sku', 'image', 'price', 'stock']);

        $products = $listings->groupBy('item_id')->map(function ($group, $itemId) use ($recSet, $advertisedIds) {
            $first = $group->first();
            $iid = (int) $itemId;

            return [
                'item_id' => $iid,
                'name' => $first->name,
                'sku' => $group->pluck('sku')->filter()->first(),
                'image' => $first->image,
                'price' => (int) $group->min('price'),
                'stock' => (int) $group->sum('stock'),
                'recommended' => isset($recSet[$iid]),
                'already_advertised' => isset($advertisedIds[$iid]),
            ];
        })->sortByDesc('recommended')->values();

        return [
            'shops' => $shops->values(),
            'shop_id' => $shop->id,
            'products' => $products,
        ];
    }

    public function suggest(Request $request)
    {
        $d = $request->validate([
            'shop_id' => 'required|integer',
            'item_id' => 'required|integer',
            'bidding_method' => 'nullable|in:auto,manual',
        ]);
        $shop = MarketplaceShop::where('user_id', $request->user()->id)
            ->where('platform', 'shopee')
            ->where('status', 'active')
            ->where('is_demo', false)
            ->findOrFail($d['shop_id']);

        $api = new ShopeeClient;
        $budget = null;
        $roi = null;
        try {
            $b = $api->adsBudgetSuggestion($shop, (int) $d['item_id'], $d['bidding_method'] ?? 'auto');
            $budget = (int) round((float) ($b['suggested_budget'] ?? $b['budget'] ?? $b['min_budget'] ?? 0)) ?: null;
        } catch (Throwable) {
        }
        try {
            $r = $api->adsRoiTargetSuggestion($shop, (int) $d['item_id']);
            $val = (float) ($r['recommended_roi_target'] ?? $r['roi_target'] ?? $r['lower_bound'] ?? 0);
            $roi = $val > 0 ? round($val, 1) : null;
        } catch (Throwable) {
        }

        return [
            'budget_suggested' => $budget,
            'roi_suggested' => $roi,
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'shop_id' => 'required|integer',
            'item_ids' => 'required|array|min:1|max:20',
            'item_ids.*' => 'integer|min:1',
            'bidding_method' => 'required|in:auto,manual',
            'budget' => 'required|integer|min:25000|max:1000000000',
            'roas_target' => 'nullable|numeric|min:0.5|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $shop = MarketplaceShop::where('user_id', $request->user()->id)
            ->where('platform', 'shopee')
            ->where('status', 'active')
            ->where('is_demo', false)
            ->findOrFail($d['shop_id']);

        $start = ! empty($d['start_date'])
            ? Carbon::parse($d['start_date'], 'Asia/Jakarta')->startOfDay()->timestamp
            : now('Asia/Jakarta')->timestamp;

        $payload = [
            'budget' => (int) $d['budget'],
            'start_time' => $start,
            'bidding_method' => $d['bidding_method'],
            'item_id_list' => array_values(array_map('intval', $d['item_ids'])),
            'enhanced_cpc' => $d['bidding_method'] === 'manual',
        ];
        if (! empty($d['end_date'])) {
            $payload['end_time'] = Carbon::parse($d['end_date'], 'Asia/Jakarta')->endOfDay()->timestamp;
        }
        if ($d['bidding_method'] === 'auto' && ! empty($d['roas_target'])) {
            $payload['roas_target'] = (float) $d['roas_target'];
        }

        try {
            $res = (new ShopeeClient)->createManualProductAd($shop, $payload);
        } catch (ShopeeException $e) {
            abort(422, 'Shopee menolak pembuatan iklan: '.$e->getMessage());
        }

        try {
            (new ShopeeSync)->ads($shop);
        } catch (Throwable) {
        }

        ActivityLog::record($request->user(), 'shopee_ads.create', "Membuat iklan Shopee pada toko {$shop->shop_name} (".count($d['item_ids']).' produk)');

        return [
            'ok' => true,
            'campaign_id' => $res['campaign_id'] ?? null,
        ];
    }
}

