<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Support\CheckoutService;
use App\Support\WhatsApp;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private const SORTABLE = ['created_at', 'total', 'customer_name', 'city', 'status', 'payment_status'];

    private const STATUSES = ['pending', 'processing', 'completed', 'cancelled', 'refunded'];

    public function index(Request $request)
    {
        $query = $this->filtered($request);

        $stats = (clone $query)->toBase()->selectRaw("
            COUNT(*) as total_orders,
            COALESCE(SUM(payment_status = 'paid'), 0) as total_paid,
            COALESCE(SUM(payment_status = 'unpaid'), 0) as unpaid_orders,
            COALESCE(SUM(status = 'refunded'), 0) as refunded_orders,
            COALESCE(SUM(shipping_status = 'delivered'), 0) as delivered_orders,
            COALESCE(SUM(shipping_status IN ('picked_up', 'in_transit', 'delivered')), 0) as shipped_orders,
            COALESCE(SUM(quantity), 0) as ordered_items,
            COALESCE(SUM(total), 0) as gross_revenue
        ")->first();
        $stats = array_map('intval', (array) $stats);
        $stats['paid_ratio'] = $stats['total_orders'] ? round($stats['total_paid'] / $stats['total_orders'] * 100, 1) : 0;

        $orders = $query->with(['product:id,name,type', 'marketplaceShop:id,shop_name,is_demo'])
            ->orderBy(in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at', $request->query('dir') === 'asc' ? 'asc' : 'desc')
            ->paginate(min(1000, max(1, $request->integer('per_page', 10))));

        return ['orders' => $orders, 'stats' => $stats, 'bookings' => $this->bookings($request)];
    }

    /**
     * Shopee "Pengiriman Kilat" reservations for the Perlu dikirim / Siap diserahkan tabs. They are listed beside
     * the orders (Seller Centre counts them in "Perlu Dikirim") but never mixed into order stats or revenue.
     */
    private function bookings(Request $request): array
    {
        $map = ['to_ship' => 'READY_TO_SHIP', 'processed' => 'PROCESSED'];
        $status = $map[$request->query('shopee_state')] ?? null;
        if ($request->query('channel') !== 'shopee' || ! $status) {
            return ['total' => 0, 'data' => []];
        }
        $q = \App\Models\ShopeeBooking::where('user_id', $request->user()->id)->where('booking_status', $status)
            ->when($request->query('marketplace_shop_id'), fn ($b, $id) => $b->where('marketplace_shop_id', $id))
            ->when($request->query('search'), fn ($b, $s) => $b->where(fn ($w) => $w->where('booking_sn', 'like', "%{$s}%")->orWhere('tracking_number', 'like', "%{$s}%")));
        $total = (clone $q)->count();

        return ['total' => $total, 'data' => $request->integer('page', 1) > 1 ? [] : $q->with('marketplaceShop:id,shop_name')->latest('booked_at')->limit(200)->get()];
    }

    public function show(Request $request, Order $order)
    {
        $this->authorizeOwner($request, $order);

        return $order->load('product');
    }

    /** "Cek resi": courier checkpoints for one order (Shopee, BinderByte or Pesanin's own history). */
    public function tracking(Request $request, Order $order)
    {
        $this->authorizeOwner($request, $order);

        return \App\Support\Tracking::for($order->load('marketplaceShop'));
    }

    /** "Input Order": the seller fills the same checkout form on behalf of a buyer. */
    public function store(Request $request)
    {
        $request->validate(['product_id' => ['required', Rule::exists('products', 'id')->where('user_id', $request->user()->id)]]);
        $product = $request->user()->products()->with('user')->findOrFail($request->input('product_id'));
        $data = $request->validate(CheckoutService::rules($product), CheckoutService::messages());

        $order = CheckoutService::place($product, $data, ['source' => 'manual', 'utm_source' => $data['utm_source'] ?? 'manual']);
        ActivityLog::record($request->user(), 'order.create', "Input order manual {$order->code}");

        return response()->json($order, 201);
    }

    public function update(Request $request, Order $order)
    {
        $this->authorizeOwner($request, $order);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'payment_status' => 'sometimes|in:unpaid,paid',
            'shipping_status' => 'sometimes|in:not_shipped,picked_up,in_transit,delivered,returned',
            'tracking_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'customer_name' => 'sometimes|string|max:120',
            'phone' => 'sometimes|string|max:30',
            'address' => 'nullable|string',
        ]);
        $this->applyTransition($order, $data);
        ActivityLog::record($request->user(), 'order.update', "Mengubah pesanan {$order->code}");

        return $order->load('product');
    }

    public function bulkUpdate(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'payment_status' => 'nullable|in:unpaid,paid',
            'action' => 'nullable|in:archive,unarchive,delete',
        ]);
        $orders = $request->user()->orders()->whereIn('id', $data['ids'])->get();

        foreach ($orders as $order) {
            match ($data['action'] ?? null) {
                'archive' => $order->update(['archived_at' => now()]),
                'unarchive' => $order->update(['archived_at' => null]),
                'delete' => $order->delete(),
                default => $this->applyTransition($order, array_filter(['status' => $data['status'] ?? null, 'payment_status' => $data['payment_status'] ?? null])),
            };
        }
        ActivityLog::record($request->user(), 'order.bulk', "Bulk update {$orders->count()} pesanan");

        return ['updated' => $orders->count()];
    }

    /** Digital product: (re)send the access link to the buyer now. */
    public function digitalSend(Request $request, Order $order)
    {
        $this->authorizeOwner($request, $order);
        $order->load('product', 'user');
        abort_unless($order->product?->type === 'digital', 422, 'Pesanan ini bukan produk digital.');
        abort_unless($order->payment_status === 'paid', 422, 'Pesanan belum dibayar.');
        $r = \App\Support\OrderAutomation::deliver($order);
        ActivityLog::record($request->user(), 'order.digital', "Kirim produk digital {$order->code}");

        return $r + ['order' => $order->fresh()];
    }

    public function destroy(Request $request, Order $order)
    {
        $this->authorizeOwner($request, $order);
        $order->delete();
        ActivityLog::record($request->user(), 'order.delete', "Menghapus pesanan {$order->code}");

        return response()->noContent();
    }

    /** Build a wa.me link from a follow-up template and count the follow-up. */
    public function followup(Request $request, Order $order)
    {
        $this->authorizeOwner($request, $order);
        $data = $request->validate(['event' => 'required|string']);
        $template = $request->user()->followupTemplates()->where('event', $data['event'])->firstOrFail();
        $order->load('product', 'user');
        $order->increment('followup_count');

        return ['url' => WhatsApp::link($order->phone, WhatsApp::fill($template->message, $order))];
    }

    public function export(Request $request)
    {
        $orders = $this->filtered($request)->with('product:id,name')->latest()->get();
        $cols = ['code', 'created_at', 'customer_name', 'phone', 'address', 'district', 'city', 'province', 'product', 'variant', 'quantity',
            'subtotal', 'shipping_cost', 'cod_fee', 'total', 'payment_method', 'payment_status', 'status', 'courier', 'shipping_status', 'tracking_number', 'utm_source'];

        return response()->streamDownload(function () use ($orders, $cols) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $cols);
            foreach ($orders as $o) {
                fputcsv($out, array_map(fn ($c) => $c === 'product' ? $o->product?->name : (string) $o->{$c}, $cols));
            }
            fclose($out);
        }, 'orders-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Status side effects: paid → processing, completed timestamp, restock on cancel/refund. */
    private function applyTransition(Order $order, array $data): void
    {
        if (($data['payment_status'] ?? null) === 'paid' && $order->payment_status !== 'paid') {
            $data['paid_at'] = now();
            if ($order->status === 'pending' && ! isset($data['status'])) {
                $data['status'] = 'processing';
            }
        }
        if (($data['status'] ?? null) === 'completed' && ! $order->completed_at) {
            $data['completed_at'] = now();
        }
        if (in_array($data['status'] ?? null, ['cancelled', 'refunded']) && ! in_array($order->status, ['cancelled', 'refunded'])) {
            $this->restock($order);
        }
        $order->update($data);
    }

    private function restock(Order $order): void
    {
        $product = $order->product;
        if (! $product?->track_stock) {
            return;
        }
        $variants = $product->variants ?? [];
        foreach ($variants as &$v) {
            if (($v['name'] ?? null) === $order->variant && isset($v['stock'])) {
                $v['stock'] += $order->quantity;
                $product->update(['variants' => $variants]);

                return;
            }
        }
        $product->increment('stock', $order->quantity);
    }

    private function filtered(Request $request): Builder
    {
        $q = $request->user()->orders()->getQuery();

        match ($request->query('view', 'all')) {
            'abandoned' => $q->where('is_abandoned', true),
            'archived' => $q->where('is_abandoned', false)->whereNotNull('archived_at'),
            default => $q->active(),
        };

        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }
        if ($type = $request->query('type')) {
            // Marketplace orders are always physical goods, even before their items are linked to a product.
            $q->where(fn ($w) => $w->whereHas('product', fn ($p) => $p->withTrashed()->where('type', $type))
                ->when($type === 'physical', fn ($ww) => $ww->orWhere('channel', 'shopee')));
        }
        foreach (['product_id', 'status', 'payment_status', 'payment_method', 'courier', 'shipping_status', 'source', 'channel', 'marketplace_shop_id'] as $f) {
            $q->when($request->query($f), fn ($qq, $v) => $qq->where($f, $v));
        }
        // "Pesanan Web" never mixes in marketplace orders; the Shopee page asks for channel=shopee explicitly.
        if (! $request->query('channel')) {
            $q->where('channel', '!=', 'shopee');
        }
        if ($state = $request->query('shopee_state')) {
            // Like Seller Centre, a buyer's pending cancel request (IN_CANCEL) stays in "Perlu Dikirim" until the seller answers:
            // under "Siap diserahkan" once a resi exists, otherwise under "Perlu dikirim".
            $inCancel = fn ($w, bool $arranged) => $w->where('external_status', 'IN_CANCEL')
                ->where(fn ($t) => $arranged ? $t->whereNotNull('tracking_number')->where('tracking_number', '!=', '') : $t->whereNull('tracking_number')->orWhere('tracking_number', ''));
            match ($state) {
                'to_ship' => $q->where(fn ($w) => $w->where('external_status', 'READY_TO_SHIP')->orWhere(fn ($x) => $inCancel($x, false))),
                // Already arranged; a failed pickup (RETRY_SHIP) waits for the courier to come back.
                'processed' => $q->where(fn ($w) => $w->whereIn('external_status', ['PROCESSED', 'RETRY_SHIP'])->orWhere(fn ($x) => $inCancel($x, true))),
                'shipping' => $q->whereIn('external_status', ['SHIPPED', 'TO_CONFIRM_RECEIVE']),
                'done' => $q->where('external_status', 'COMPLETED'),
                'cancelled' => $q->whereIn('external_status', ['CANCELLED', 'TO_RETURN']),
                'unpaid' => $q->where('external_status', 'UNPAID'),
                default => $q->where('external_status', $state),
            };
        }
        if ($traffic = $request->query('traffic')) {
            \App\Support\Traffic::scope($q->where('channel', 'website'), $traffic);
        }
        if ($request->query('pickup') === 'picked') {
            $q->where('shipping_status', '!=', 'not_shipped');
        } elseif ($request->query('pickup') === 'none') {
            $q->where('shipping_status', 'not_shipped');
        }
        if ($request->boolean('duplicates')) {
            $dupes = $request->user()->orders()->active()->select('phone')->groupBy('phone')->havingRaw('COUNT(*) > 1');
            $q->whereIn('phone', $dupes);
        }
        if ($s = $request->query('search')) {
            $q->where(fn ($qq) => $qq->where('customer_name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('tracking_number', 'like', "%{$s}%")
                ->orWhere('external_order_sn', 'like', "%{$s}%"));
        }

        return $q;
    }

    private function authorizeOwner(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }
}
