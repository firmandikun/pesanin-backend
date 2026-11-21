<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceShop;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeePromotions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Marketplace → Promosi: Promo Toko, Voucher, Paket Diskon, Kombo Hemat, Flash Sale Toko, Produk Unggulan. */
class ShopeePromotionController extends Controller
{
    private function shop(Request $request): MarketplaceShop
    {
        $shop = MarketplaceShop::where('user_id', $request->user()->id)->findOrFail((int) $request->input('shop_id'));
        abort_if($shop->is_demo, 422, 'Toko contoh tidak terhubung ke Shopee.');

        return $shop;
    }

    private function shopee(callable $fn)
    {
        try {
            return $fn();
        } catch (ShopeeException $e) {
            abort(422, 'Shopee menolak: '.$e->getMessage());
        }
    }

    public function index(Request $request, string $type)
    {
        abort_unless(in_array($type, ShopeePromotions::TYPES, true), 404);
        $shop = $this->shop($request);

        return ['promotions' => $this->shopee(fn () => collect((new ShopeePromotions)->list($shop, $type))
            ->sortBy(fn ($p) => [array_search($p['status'], ['ongoing', 'upcoming', 'inactive', 'unknown', 'expired'], true), -($p['start_time'] ?? 0)])->values()->all())];
    }

    public function timeSlots(Request $request)
    {
        $d = $request->validate(['shop_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d']);
        $from = max(now()->timestamp + 300, \Carbon\Carbon::parse($d['date'], 'Asia/Jakarta')->startOfDay()->timestamp);
        $to = \Carbon\Carbon::parse($d['date'], 'Asia/Jakarta')->endOfDay()->timestamp;
        abort_if($to <= $from, 422, 'Pilih tanggal hari ini atau setelahnya.');
        $shop = $this->shop($request);

        return ['slots' => $this->shopee(fn () => (new ShopeePromotions)->timeSlots($shop, $from, $to))];
    }

    public function store(Request $request, string $type)
    {
        abort_unless(in_array($type, ShopeePromotions::TYPES, true), 404);
        $timed = ! in_array($type, ['flash', 'top_picks'], true);
        $needsItems = $type !== 'voucher' || $request->input('voucher_type') === 'product';
        $rules = [
            'shop_id' => 'required|integer',
            'name' => [Rule::requiredIf($type !== 'flash'), 'nullable', 'string', 'max:'.($type === 'top_picks' ? 24 : 100)],
            'start_time' => [Rule::requiredIf($timed), 'nullable', 'integer', 'gt:'.(now()->timestamp + 60)],
            'end_time' => [Rule::requiredIf($timed), 'nullable', 'integer', 'gt:start_time'],
            'items' => [Rule::requiredIf($needsItems), 'array', 'max:100'],
            'items.*.item_id' => 'required|integer',
            'items.*.purchase_limit' => 'nullable|integer|min:0|max:1000',
            'items.*.models' => 'array',
            'items.*.models.*.model_id' => 'required|integer',
            'items.*.models.*.price' => 'nullable|numeric|min:0',
            'items.*.models.*.stock' => 'nullable|integer|min:1',
        ];
        $rules += match ($type) {
            'voucher' => [
                'code' => 'required|alpha_num|max:5', 'voucher_type' => 'required|in:shop,product', 'reward_type' => 'required|in:fix_amount,percentage',
                'usage_quantity' => 'required|integer|min:1|max:200000', 'min_basket_price' => 'nullable|numeric|min:0',
                'discount_amount' => 'required_if:reward_type,fix_amount|nullable|numeric|min:1', 'percentage' => 'required_if:reward_type,percentage|nullable|integer|min:1|max:99',
                'max_price' => 'nullable|numeric|min:0',
            ],
            'bundle' => ['rule_type' => 'required|in:fix_price,percentage,discount_value', 'value' => 'required|numeric|min:1', 'min_amount' => 'required|integer|min:2|max:100', 'purchase_limit' => 'nullable|integer|min:0'],
            'addon' => ['main_items' => 'required|array|min:1|max:100', 'main_items.*.item_id' => 'required|integer', 'purchase_limit' => 'nullable|integer|min:1|max:100'],
            'flash' => ['timeslot_id' => 'required|integer'],
            'top_picks' => ['items' => 'required|array|min:4|max:8', 'activate' => 'boolean'],
            default => [],
        };
        $d = $request->validate($rules, [
            'start_time.gt' => 'Waktu mulai harus di masa depan.', 'end_time.gt' => 'Waktu selesai harus setelah waktu mulai.',
            'items.required' => 'Pilih minimal satu produk.', 'items.min' => 'Pilih 4–8 produk.', 'items.max' => 'Terlalu banyak produk.',
        ]);
        $shop = $this->shop($request);

        return $this->shopee(fn () => (new ShopeePromotions)->create($shop, $type, $d));
    }

    public function stop(Request $request, string $type, string $id)
    {
        abort_unless(in_array($type, ShopeePromotions::TYPES, true), 404);
        $d = $request->validate(['shop_id' => 'required|integer', 'status' => 'required|string']);
        $shop = $this->shop($request);
        $this->shopee(fn () => (new ShopeePromotions)->stop($shop, $type, $id, $d['status']));

        return ['ok' => true];
    }

    public function toggleTopPicks(Request $request, string $id)
    {
        $d = $request->validate(['shop_id' => 'required|integer', 'active' => 'required|boolean']);
        $shop = $this->shop($request);
        $this->shopee(fn () => (new ShopeePromotions)->toggleTopPicks($shop, $id, $d['active']));

        return ['ok' => true];
    }
}
