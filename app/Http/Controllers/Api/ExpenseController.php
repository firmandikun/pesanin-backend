<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\Analytics;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengeluaran per toko or for the whole business. Lists the seller's own entries together with ad spend
 * (Shopee Ads synced daily incl. PPN, and ads entered by hand) so every outflow is in one place.
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date', 'shop_id' => 'nullable|integer',
            'category' => ['nullable', Rule::in([...array_keys(Expense::CATEGORIES), 'ads'])],
            'search' => 'nullable|string|max:100',
        ]);
        $to = Carbon::parse($data['to'] ?? today())->toDateString();
        $from = Carbon::parse($data['from'] ?? Carbon::parse($to)->subDays(29))->toDateString();
        $shopId = $data['shop_id'] ?? null;
        $category = $data['category'] ?? null;
        $search = $data['search'] ?? null;
        $shops = $user->marketplaceShops()->get()->keyBy('id');

        $own = Expense::where('user_id', $user->id)->whereBetween('spent_on', [$from, $to])
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', "%{$search}%")->orWhere('vendor', 'like', "%{$search}%")))
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id, 'source' => 'manual', 'spent_on' => $e->spent_on->toDateString(), 'category' => $e->category,
                'category_label' => Expense::CATEGORIES[$e->category][0] ?? $e->category, 'affects_profit' => Expense::CATEGORIES[$e->category][1] ?? true,
                'description' => $e->description, 'amount' => $e->amount, 'payment_method' => $e->payment_method, 'vendor' => $e->vendor,
                'note' => $e->note, 'marketplace_shop_id' => $e->marketplace_shop_id, 'shop_name' => $shops[$e->marketplace_shop_id]->shop_name ?? null,
            ]);

        $ads = $user->adSpends()->whereBetween('spent_on', [$from, $to])->where('amount', '>', 0)
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->when($search, fn ($q) => $q->where('campaign', 'like', "%{$search}%"))
            ->get()
            ->map(function ($a) use ($shops) {
                $amount = Analytics::adCost($a, $shops);

                return [
                    'id' => 'ad-'.$a->id, 'source' => $a->synced ? 'synced' : 'ads', 'spent_on' => $a->spent_on->toDateString(), 'category' => 'ads',
                    'category_label' => 'Biaya Iklan', 'affects_profit' => true,
                    'description' => ($a->synced ? 'Shopee Ads - pengeluaran iklan harian' : ($a->campaign ?: 'Biaya iklan')),
                    'amount' => $amount, 'payment_method' => null, 'vendor' => Analytics::AD_PLATFORMS[$a->platform] ?? $a->platform,
                    'note' => $a->synced ? 'Tersinkron otomatis, termasuk PPN '.rtrim(rtrim(number_format((float) ($shops[$a->marketplace_shop_id]->ads_ppn ?? 11), 2), '0'), '.').'%' : $a->note,
                    'marketplace_shop_id' => $a->marketplace_shop_id, 'shop_name' => $shops[$a->marketplace_shop_id]->shop_name ?? null,
                ];
            });

        $all = $own->concat($ads);
        $rows = $all->when($category, fn ($c) => $c->where('category', $category))->sortByDesc('spent_on')->values();

        return [
            'from' => $from, 'to' => $to,
            'data' => $rows,
            'summary' => [
                'total' => (int) $all->sum('amount'),
                'count' => $all->count(),
                'affects_profit' => (int) $all->where('affects_profit', true)->sum('amount'),
                'cash_only' => (int) $all->where('affects_profit', false)->sum('amount'),
                'by_category' => $all->groupBy('category')->map(fn ($g, $k) => ['category' => $k, 'label' => $g->first()['category_label'], 'amount' => (int) $g->sum('amount')])->values(),
            ],
            'categories' => collect(Expense::CATEGORIES)->map(fn ($v, $k) => ['value' => $k, 'label' => $v[0], 'affects_profit' => $v[1]])->values(),
            'payment_methods' => collect(Expense::PAYMENT_METHODS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ];
    }

    public function store(Request $request)
    {
        $e = new Expense($this->validated($request));
        $e->user_id = $request->user()->id;
        $e->save();

        return response()->json($e, 201);
    }

    public function update(Request $request, Expense $expense)
    {
        abort_unless($expense->user_id === $request->user()->id, 404);
        $expense->update($this->validated($request));

        return $expense;
    }

    public function destroy(Request $request, Expense $expense)
    {
        abort_unless($expense->user_id === $request->user()->id, 404);
        $expense->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'marketplace_shop_id' => ['nullable', Rule::exists('marketplace_shops', 'id')->where('user_id', $request->user()->id)],
            'spent_on' => 'required|date|before_or_equal:today',
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'description' => 'required|string|max:150',
            'amount' => 'required|integer|min:1|max:10000000000',
            'payment_method' => ['nullable', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'vendor' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:255',
        ]);
    }
}
