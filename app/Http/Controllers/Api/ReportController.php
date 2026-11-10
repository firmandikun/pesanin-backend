<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\Support\Analytics;
use App\Support\Traffic;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Dashboard: what needs action now, the period's money (omset → laba bersih, vs the previous
     * period of the same length), daily trend, where orders come from, best sellers, latest orders.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $days = in_array($request->integer('days', 7), [1, 7, 30, 90], true) ? $request->integer('days', 7) : 7;
        $to = now()->endOfDay();
        $from = now()->subDays($days - 1)->startOfDay();
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        $orders = $user->orders()->active();
        $web = (clone $orders)->where('channel', '!=', 'shopee');
        $shopee = (clone $orders)->where('channel', 'shopee');

        $now = Analytics::profit($user, $from, $to);
        $prev = Analytics::profit($user, $prevFrom, $prevTo)['summary'];
        $pick = fn (array $s) => [
            'revenue' => $s['revenue'], 'net_profit' => $s['net_profit'], 'margin' => $s['margin'], 'orders' => $s['orders'],
            'items' => $s['items'], 'ads' => $s['ads'], 'roas' => $s['roas'], 'fees' => $s['fees'], 'cogs' => $s['cogs'],
            'aov' => $s['orders'] ? (int) round($s['revenue'] / $s['orders']) : 0,
        ];

        return [
            'days' => $days,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            // Back-compat with the old dashboard fields.
            'pending_orders' => (clone $web)->where('status', 'pending')->count(),
            'awaiting_payment_confirmation' => (clone $web)->where('payment_method', 'bank_transfer')->where('payment_status', 'unpaid')->whereNotIn('status', ['cancelled'])->count(),
            'actions' => [
                'pending' => (clone $web)->where('status', 'pending')->count(),
                'confirm_transfer' => (clone $web)->where('payment_method', 'bank_transfer')->where('payment_status', 'unpaid')->whereNotIn('status', ['cancelled'])->count(),
                'shopee_to_ship' => (clone $shopee)->where('external_status', 'READY_TO_SHIP')->count(),
                'shopee_to_print' => (clone $shopee)->whereIn('external_status', ['PROCESSED', 'RETRY_SHIP'])->whereNotNull('tracking_number')->count(),
                'abandoned_today' => $user->orders()->where('is_abandoned', true)->whereNull('archived_at')->whereDate('created_at', today())->count(),
            ],
            'summary' => $pick($now['summary']),
            'previous' => $pick($prev),
            'missing_cost_orders' => $now['summary']['missing_cost_orders'],
            'daily' => $now['daily'],
            'by_channel' => collect($now['by_channel'])->filter(fn ($c) => $c['orders'] > 0)->map(fn ($c) => [
                'key' => $c['key'], 'label' => $c['label'], 'orders' => $c['orders'], 'revenue' => $c['revenue'], 'net_profit' => $c['net_profit'],
            ])->values(),
            'top_products' => collect($now['products'])->take(5)->map(fn ($p) => [
                'name' => $p['name'], 'qty' => $p['qty'], 'revenue' => $p['revenue'],
                'margin' => $p['revenue'] ? round($p['gross'] / $p['revenue'] * 100) : null,
            ])->values(),
            'today' => [
                'orders' => (clone $orders)->whereDate('created_at', today())->count(),
                'revenue' => (int) (clone $orders)->whereDate('created_at', today())->sum('total'),
            ],
            'onboarding' => [
                'product' => $user->products()->exists(),
                'order' => $user->orders()->where('is_abandoned', false)->exists(),
                'tracking' => $user->orders()->whereNotNull('tracking_number')->exists(),
                'followup' => $user->followupTemplates()->where('updated_at', '>', $user->created_at->addSecond())->exists(),
                'address' => $user->addresses()->exists(),
                'whatsapp' => $user->whatsapp && ! empty($user->bank_accounts),
            ],
            'recent_orders' => (clone $orders)->with(['product:id,name', 'marketplaceShop:id,shop_name'])->latest()->limit(6)
                ->get(['id', 'code', 'channel', 'utm_source', 'customer_name', 'product_id', 'marketplace_shop_id', 'external_order_sn', 'external_status', 'items', 'total', 'status', 'payment_status', 'created_at']),
        ];
    }

    public function sales(Request $request)
    {
        [$from, $to] = $this->range($request);
        $base = $request->user()->orders()->active()
            ->whereBetween('created_at', [$from, $to])
            ->when($request->query('product_id'), fn ($q, $v) => $q->where('product_id', $v));
        $rows = (clone $base)
            ->selectRaw("DATE(created_at) as date, COUNT(*) as orders,
                SUM(payment_status = 'paid') as paid_orders,
                SUM(quantity) as items,
                SUM(total) as gross,
                SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END) as net")
            ->groupBy('date')->get()->keyBy('date');

        $sourceRows = (clone $base)->with('marketplaceShop:id,shop_name')
            ->get(['id', 'channel', 'marketplace_shop_id', 'utm_source', 'payment_status', 'quantity', 'total'])
            ->groupBy(function ($order) {
                if ($order->channel === 'shopee') {
                    return 'shopee:'.$order->marketplace_shop_id;
                }

                return $order->channel === 'manual' ? 'manual' : 'social:'.Traffic::of($order->utm_source);
            })->map(function ($orders, string $key) {
                $first = $orders->first();
                $marketplace = str_starts_with($key, 'shopee:');
                $label = match (true) {
                    $marketplace => 'Shopee · '.($first->marketplaceShop?->shop_name ?? 'Toko tidak diketahui'),
                    $key === 'manual' => 'Media Sosial · Input manual',
                    default => 'Media Sosial · '.Traffic::label(substr($key, 7)),
                };

                return [
                    'key' => $key,
                    'type' => $marketplace ? 'marketplace' : 'social_media',
                    'platform' => $marketplace ? 'shopee' : ($key === 'manual' ? 'manual' : substr($key, 7)),
                    'shop_id' => $marketplace ? $first->marketplace_shop_id : null,
                    'shop_name' => $marketplace ? $first->marketplaceShop?->shop_name : null,
                    'label' => $label,
                    'orders' => $orders->count(),
                    'paid_orders' => $orders->where('payment_status', 'paid')->count(),
                    'items' => (int) $orders->sum('quantity'),
                    'gross' => (int) $orders->sum('total'),
                    'net' => (int) $orders->where('payment_status', 'paid')->sum('total'),
                ];
            })->sortByDesc('gross')->values();

        $daily = collect(CarbonPeriod::create($from, $to))->map(function (Carbon $d) use ($rows) {
            $r = $rows->get($d->toDateString());

            return [
                'date' => $d->toDateString(),
                'orders' => (int) ($r->orders ?? 0),
                'paid_orders' => (int) ($r->paid_orders ?? 0),
                'items' => (int) ($r->items ?? 0),
                'gross' => (int) ($r->gross ?? 0),
                'net' => (int) ($r->net ?? 0),
            ];
        })->values();

        return [
            'daily' => $daily,
            'sources' => $sourceRows,
            'totals' => [
                'orders' => $daily->sum('orders'),
                'paid_orders' => $daily->sum('paid_orders'),
                'items' => $daily->sum('items'),
                'gross' => $daily->sum('gross'),
                'net' => $daily->sum('net'),
            ],
        ];
    }

    public function statistics(Request $request)
    {
        [$from, $to] = $this->range($request);
        $base = fn () => $request->user()->orders()->active()->whereBetween('orders.created_at', [$from, $to]);
        $group = fn (string $col) => $base()->selectRaw("COALESCE($col, '-') as label, COUNT(*) as total")
            ->groupBy('label')->orderByDesc('total')->limit(10)->get();

        return [
            'by_status' => $group('status'),
            'by_payment_method' => $group('payment_method'),
            'by_payment_status' => $group('payment_status'),
            'by_city' => $group('city'),
            'by_courier' => $group('courier'),
            'by_utm_source' => $group('utm_source'),
            'top_products' => $base()->join('products', 'products.id', '=', 'orders.product_id')
                ->selectRaw('products.name as label, COUNT(*) as total, SUM(orders.quantity) as qty, SUM(orders.total) as revenue')
                ->groupBy('products.name')->orderByDesc('total')->limit(10)->get(),
            'abandoned' => $request->user()->orders()->where('is_abandoned', true)->whereBetween('created_at', [$from, $to])->count(),
            'views' => (int) $request->user()->products()->sum('views'),
        ];
    }

    public function profit(Request $request)
    {
        [$from, $to] = $this->range($request);
        $data = $request->validate(['channel' => 'nullable|in:website,manual,shopee', 'shop_id' => 'nullable|integer']);

        return \App\Support\Analytics::profit($request->user(), $from, $to, $data['channel'] ?? null, $data['shop_id'] ?? null)
            + ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    public function research(Request $request)
    {
        return ['products' => \App\Support\Analytics::research($request->user())];
    }

    private function range(Request $request): array
    {
        $to = Carbon::parse($request->query('to', today()->toDateString()))->endOfDay();
        $from = Carbon::parse($request->query('from', $to->copy()->subDays(29)->toDateString()))->startOfDay();

        return [$from, $to];
    }
}
