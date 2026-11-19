<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\ShopeeReview;
use App\Support\Shopee\ShopeeReviews;
use Illuminate\Http\Request;

/** Marketplace → Ulasan: Shopee buyer reviews, answered one by one or in bulk with templates. */
class ShopeeReviewController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $base = ShopeeReview::where('user_id', $user->id)->when($request->query('shop'), fn ($q, $id) => $q->where('marketplace_shop_id', $id));
        $q = (clone $base)->with('shop:id,shop_name')
            ->when($request->query('rating') === 'low', fn ($w) => $w->where('rating', '<=', 3))
            ->when(ctype_digit((string) $request->query('rating')), fn ($w) => $w->where('rating', (int) $request->query('rating')))
            ->when($request->query('replied') === 'no', fn ($w) => $w->whereNull('replied_at'))
            ->when($request->query('replied') === 'yes', fn ($w) => $w->whereNotNull('replied_at'))
            ->when($request->query('search'), fn ($w, $s) => $w->where(fn ($x) => $x->where('comment', 'like', "%{$s}%")->orWhere('buyer', 'like', "%{$s}%")->orWhere('order_sn', 'like', "%{$s}%")))
            ->orderByDesc('reviewed_at');
        $page = $q->paginate(min(100, max(1, $request->integer('per_page', 20))));
        $names = MarketplaceListing::where('user_id', $user->id)->whereIn('item_id', $page->getCollection()->pluck('item_id')->unique())
            ->get(['item_id', 'name', 'image'])->unique('item_id')->keyBy('item_id');
        $page->getCollection()->transform(fn ($r) => $r->setAttribute('product', $names[$r->item_id] ?? null));

        return [
            'reviews' => $page,
            'stats' => [
                'total' => (clone $base)->count(),
                'average' => round((float) (clone $base)->avg('rating'), 2),
                'unreplied' => (clone $base)->whereNull('replied_at')->count(),
                'low_unreplied' => (clone $base)->whereNull('replied_at')->where('rating', '<=', 3)->count(),
                'by_rating' => collect(range(5, 1))->mapWithKeys(fn ($s) => [$s => (clone $base)->where('rating', $s)->count()]),
            ],
            'settings' => ShopeeReviews::settings($user),
        ];
    }

    /** "Ambil ulasan": sync now. */
    public function sync(Request $request)
    {
        $new = 0;
        $errors = [];
        $shops = $request->user()->marketplaceShops()->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false)
            ->when($request->input('shop'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($shops as $shop) {
            try {
                $new += count(ShopeeReviews::sync($shop, 10));
            } catch (\Throwable $e) {
                $errors[] = "{$shop->shop_name}: ".mb_substr($e->getMessage(), 0, 150);
            }
        }

        return ['new' => $new, 'errors' => $errors];
    }

    /**
     * Reply. Either {replies: [{id, text}]} for typed replies, or {ids: [...]} to answer each with the template of
     * its star rating (bulk).
     */
    public function reply(Request $request)
    {
        $d = $request->validate([
            'replies' => 'nullable|array|max:200', 'replies.*.id' => 'required|integer', 'replies.*.text' => 'required|string|min:2|max:500',
            'ids' => 'nullable|array|max:200', 'ids.*' => 'integer',
        ]);
        $user = $request->user();
        $texts = collect($d['replies'] ?? [])->mapWithKeys(fn ($r) => [$r['id'] => $r['text']])->all();
        if (! empty($d['ids'])) {
            $cfg = ShopeeReviews::settings($user);
            $reviews = ShopeeReview::where('user_id', $user->id)->whereIn('id', $d['ids'])->get();
            $names = MarketplaceListing::where('user_id', $user->id)->whereIn('item_id', $reviews->pluck('item_id'))->pluck('name', 'item_id');
            foreach ($reviews as $r) {
                $texts[$r->id] ??= ShopeeReviews::fill(ShopeeReviews::templateFor($cfg, $r->rating), $r, $names[$r->item_id] ?? null);
            }
        }
        abort_if(! $texts, 422, 'Pilih ulasan yang mau dibalas.');

        return ShopeeReviews::reply($user, $texts);
    }
}
