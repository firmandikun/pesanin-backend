<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Plans;
use App\Support\Research\GoogleTrends;
use App\Support\Research\ShopeeAffiliate;
use Illuminate\Http\Request;
use Throwable;

/** Riset Pasar: products on Shopee (Affiliate API) and search interest on Google Trends (SerpApi). */
class MarketResearchController extends Controller
{
    public function status(Request $request)
    {
        $u = $request->user();
        [, $shopee] = ShopeeAffiliate::resolve($u);
        [, $trends] = GoogleTrends::resolve($u);

        return [
            'shopee' => (bool) $shopee, 'trends' => (bool) $trends,
            'source' => ['shopee' => $shopee, 'trends' => $trends],
            'quota' => ['limit' => Plans::PLANS[Plans::current($u)]['limits']['research'], 'used' => Plans::researchUsed($u)],
            'periods' => GoogleTrends::PERIODS,
        ];
    }

    /** Shared (Pesanin) keys are metered per plan per day; a seller's own keys are not. */
    private function meter(Request $request, string $source): void
    {
        if ($source !== 'pesanin') {
            return;
        }
        $u = $request->user();
        $limit = Plans::PLANS[Plans::current($u)]['limits']['research'];
        if ($limit !== null && Plans::researchUsed($u) >= $limit) {
            abort(response()->json(['message' => "Kuota riset hari ini ({$limit}x) di paket ".Plans::PLANS[Plans::current($u)]['name'].' sudah habis. Coba lagi besok atau upgrade paket.', 'upgrade' => true, 'limit' => 'research'], 402));
        }
        Plans::countResearch($u);
    }

    public function shopee(Request $request)
    {
        $data = $request->validate(['keyword' => 'required|string|min:2|max:80', 'sort' => 'nullable|in:sales,relevance,price_asc,price_desc,commission', 'page' => 'nullable|integer|min:1|max:20']);
        [$client, $source] = ShopeeAffiliate::resolve($request->user());
        abort_unless($client, 422, 'Riset Shopee belum tersedia. Hubungi admin Pesanin.');
        $this->meter($request, $source);

        try {
            $res = $client->search($data['keyword'], $data['sort'] ?? 'sales', (int) ($data['page'] ?? 1));
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }
        $items = collect($res['items']);
        $prices = $items->pluck('price_min')->filter()->sort()->values();

        return $res + ['summary' => [
            'count' => $items->count(),
            'sold' => $items->sum('sold'),
            'revenue_estimate' => $items->sum('revenue_estimate'),
            'price_min' => $prices->first(),
            'price_median' => $prices->count() ? $prices[(int) floor(($prices->count() - 1) / 2)] : null,
            'price_max' => $prices->last(),
            'rating' => $items->where('rating', '>', 0)->avg('rating') ? round($items->where('rating', '>', 0)->avg('rating'), 1) : null,
            'mall_share' => $items->count() ? (int) round($items->where('shop_type', 'Mall')->count() / $items->count() * 100) : 0,
            'top_shops' => $items->groupBy('shop')->map(fn ($g, $shop) => ['shop' => $shop, 'products' => $g->count(), 'sold' => $g->sum('sold')])->sortByDesc('sold')->take(5)->values(),
        ]];
    }

    public function trends(Request $request)
    {
        $data = $request->validate(['q' => 'required|string|max:300', 'date' => 'nullable|in:'.implode(',', array_keys(GoogleTrends::PERIODS))]);
        $client = $this->trendsClient($request, true);
        $keywords = array_slice(array_filter(array_map('trim', explode(',', $data['q']))), 0, 5);

        try {
            return $client->interest($keywords, $data['date'] ?? 'today 12-m');
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }
    }

    public function related(Request $request)
    {
        $data = $request->validate(['q' => 'required|string|max:80', 'date' => 'nullable|in:'.implode(',', array_keys(GoogleTrends::PERIODS))]);
        $client = $this->trendsClient($request);

        try {
            return $client->related($data['q'], $data['date'] ?? 'today 12-m') + ['regions' => $client->regions($data['q'], $data['date'] ?? 'today 12-m')];
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }
    }

    /** Only the main trends search counts toward the quota; its related/region panels don't. */
    private function trendsClient(Request $request, bool $metered = false): GoogleTrends
    {
        [$client, $source] = GoogleTrends::resolve($request->user());
        abort_unless($client, 422, 'Riset Google Trends belum tersedia. Hubungi admin Pesanin.');
        if ($metered) {
            $this->meter($request, $source);
        }

        return $client;
    }
}
