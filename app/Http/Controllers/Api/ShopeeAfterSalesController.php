<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\ShopeeReturn;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Http\Request;
use Throwable;

/**
 * Shopee after-sales from Pesanin: answer return / refund requests (accept, offer a partial refund, accept the
 * buyer's offer, dispute), cancel an order because stock ran out, and the seller's private order note.
 */
class ShopeeAfterSalesController extends Controller
{
    private function ret(Request $request, int $id): ShopeeReturn
    {
        $r = ShopeeReturn::where('user_id', $request->user()->id)->with('marketplaceShop')->findOrFail($id);
        abort_if($r->marketplaceShop->is_demo, 422, 'Toko contoh tidak terhubung ke Shopee.');

        return $r;
    }

    private function order(Request $request, int $id): Order
    {
        $o = Order::where('user_id', $request->user()->id)->where('channel', 'shopee')->with('marketplaceShop')->findOrFail($id);
        abort_if(! $o->marketplaceShop || $o->marketplaceShop->is_demo, 422, 'Toko contoh tidak terhubung ke Shopee.');

        return $o;
    }

    private function shopee(callable $fn)
    {
        try {
            return $fn();
        } catch (ShopeeException $e) {
            abort(422, 'Shopee menolak: '.$e->getMessage());
        }
    }

    private function api(ShopeeReturn $r, string $method, string $path, array $data = []): array
    {
        return (new ShopeeClient)->call($r->marketplaceShop, $method, "/api/v2/returns/{$path}", ['return_sn' => $r->return_sn] + $data)['response'] ?? [];
    }

    /** Re-read the return from Shopee and store it. */
    private function refresh(ShopeeReturn $r): array
    {
        $d = $this->api($r, 'GET', 'get_return_detail');
        if ($d) {
            (new ShopeeSync)->upsertReturn($r->marketplaceShop, $d);
        }

        return $d;
    }

    /**
     * Everything needed to answer one request: buyer photos/videos & text, negotiation, solutions, dispute reasons.
     * Shopee's optional blocks vary in shape; anything unexpected is skipped (and logged) instead of failing the page.
     */
    public function show(Request $request, int $id)
    {
        $r = $this->ret($request, $id);
        $err = null;
        try {
            $d = $this->refresh($r);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Retur {$r->return_sn} detail: ".$e->getMessage());
            $d = [];
            $err = $e instanceof ShopeeException ? 'Shopee menolak: '.$e->getMessage() : 'Rincian terbaru dari Shopee belum bisa diambil, data yang tampil dari sinkron terakhir.';
        }
        $r->refresh();
        $open = in_array($r->status, ShopeeReturn::NEEDS_SELLER, true);
        $list = fn ($v) => is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
        $try = function (string $path) use ($r) {
            try {
                return $this->api($r, 'GET', $path);
            } catch (Throwable $e) {
                \Illuminate\Support\Facades\Log::info("Retur {$r->return_sn} {$path}: ".$e->getMessage());

                return [];
            }
        };
        $neg = is_array($d['negotiation'] ?? null) ? $d['negotiation'] : [];
        $sol = $open ? $try('get_available_solutions') : [];
        $reasons = $open ? $try('get_return_dispute_reason') : [];

        return [
            'return' => $r,
            'error' => $err,
            'images' => array_values(array_filter(array_map(fn ($x) => is_string($x) ? $x : ($x['url'] ?? $x['image_url'] ?? null), is_array($d['image'] ?? null) ? $d['image'] : []))),
            'videos' => collect($list($d['buyer_videos'] ?? null))->map(fn ($v) => $v['video_url'] ?? null)->filter()->values()->all(),
            'needs_logistics' => (bool) ($d['needs_logistics'] ?? false),
            'negotiation' => $neg ? [
                'status' => $neg['negotiation_status'] ?? null, 'solution' => $neg['latest_solution'] ?? null,
                'offer_amount' => is_numeric($neg['latest_offer_amount'] ?? null) ? (int) round($neg['latest_offer_amount']) : null,
                'offer_by' => is_string($neg['latest_offer_creator'] ?? null) ? $neg['latest_offer_creator'] : null, 'counter_limit' => $neg['counter_offer_limit'] ?? null,
                'max_refund' => is_numeric($neg['max_refundable_amount'] ?? null) ? (int) round($neg['max_refundable_amount']) : null,
            ] : null,
            'solutions' => collect($list($sol['solution'] ?? $sol['solution_list'] ?? null))
                ->map(fn ($x) => ['solution' => $x['name'] ?? $x['solution'] ?? null, 'max_refund' => (int) round((float) ($x['max_refund_amount'] ?? 0))])->filter(fn ($x) => is_string($x['solution']))->values()->all(),
            'dispute_reasons' => collect($list($reasons['dispute_reason_list'] ?? $reasons['dispute_reason'] ?? null))
                ->map(fn ($x) => ['id' => (int) ($x['reason_id'] ?? 0), 'text' => (string) ($x['reason_text'] ?? '')])->filter(fn ($x) => $x['id'])->values()->all(),
        ];
    }

    /** action: accept (refund in full / accept the return), offer, accept_offer, dispute. */
    public function act(Request $request, int $id)
    {
        $r = $this->ret($request, $id);
        $d = $request->validate([
            'action' => 'required|in:accept,offer,accept_offer,dispute',
            'solution' => 'required_if:action,offer|nullable|string|max:40',
            'amount' => 'required_if:action,offer|nullable|integer|min:0',
            'reason_id' => 'required_if:action,dispute|nullable|integer',
            'text' => 'required_if:action,dispute|nullable|string|min:10|max:1000',
            'email' => 'required_if:action,dispute|nullable|email',
        ]);
        $this->shopee(fn () => match ($d['action']) {
            'accept' => $this->api($r, 'POST', 'confirm'),
            'offer' => $this->api($r, 'POST', 'offer', ['proposed_solution' => $d['solution'], 'proposed_adjusted_refund_amount' => (float) $d['amount']]),
            'accept_offer' => $this->api($r, 'POST', 'accept_offer'),
            'dispute' => $this->api($r, 'POST', 'dispute', ['email' => $d['email'], 'dispute_reason' => (int) $d['reason_id'], 'dispute_text_reason' => $d['text']]),
        });
        try {
            $this->refresh($r);
        } catch (Throwable) {
            // the next sync picks up the new status
        }
        ActivityLog::record($request->user(), 'order.shopee_return', match ($d['action']) {
            'accept' => 'Menyetujui', 'offer' => 'Menawarkan solusi untuk', 'accept_offer' => 'Menerima tawaran pembeli untuk', default => 'Mengajukan sengketa',
        }." pengembalian Shopee {$r->return_sn}");

        return ['return' => $r->fresh(), 'message' => match ($d['action']) {
            'accept' => 'Pengembalian disetujui di Shopee.', 'offer' => 'Tawaran dikirim ke pembeli.',
            'accept_offer' => 'Tawaran pembeli diterima.', default => 'Sengketa diajukan ke Shopee.',
        }];
    }

    /** Rincian Pesanan (like Seller Centre): order, income breakdown, history and any return request. */
    public function detail(Request $request, int $id)
    {
        $o = Order::where('user_id', $request->user()->id)->where('channel', 'shopee')->with('marketplaceShop:id,shop_name,shop_id,is_demo')->findOrFail($id);
        // Income is an estimate until the order completes: refresh it from Shopee when looking at it.
        if ($o->marketplaceShop && ! $o->marketplaceShop->is_demo && ! $o->escrow_final && ! in_array($o->external_status, ['UNPAID', 'CANCELLED'], true)) {
            try {
                (new ShopeeSync)->fees(\App\Models\MarketplaceShop::find($o->marketplace_shop_id), $o);
                $o->refresh()->load('marketplaceShop:id,shop_name,shop_id,is_demo');
            } catch (Throwable) {
                // keep what is stored
            }
        }
        $o = \App\Support\OrderQuota::mask($o, $request->user());
        $history = collect([
            ['Pesanan Baru', 'Pesanan dibuat oleh pembeli.', $o->created_at],
            ['Pembayaran Dikonfirmasi', null, $o->paid_at],
            ['Pesanan Dikirim', $o->tracking_number ? "Diserahkan ke kurir, resi {$o->tracking_number}." : null, $o->shipped_at],
            [$o->external_status === 'CANCELLED' ? 'Pesanan Dibatalkan' : 'Pesanan Selesai', $o->external_status === 'CANCELLED' ? null : 'Pembeli menerima pesanan.', $o->completed_at],
        ])->filter(fn ($h) => $h[2])->map(fn ($h) => ['title' => $h[0], 'desc' => $h[1], 'at' => $h[2]->toIso8601String()])->reverse()->values();

        return [
            'order' => $o,
            // A cancelled order earns nothing: the income Shopee estimated before the cancel no longer applies.
            'cancelled' => $cancelled = $o->external_status === 'CANCELLED',
            'ads' => $ads = $cancelled ? null : $this->adCost($o),
            'cogs' => $cogs = $this->cogs($o),
            // Laba bersih: penghasilan dari Shopee − HPP − biaya iklan per konversi.
            'profit' => ! $cancelled && $o->escrow_amount !== null && $cogs['total'] !== null ? (int) $o->escrow_amount - $cogs['total'] - ($ads['per_conversion'] ?? 0) : null,
            'history' => $history,
            'return' => ShopeeReturn::where('marketplace_shop_id', $o->marketplace_shop_id)->where('order_sn', $o->external_order_sn)->latest('id')->first(['id', 'return_sn', 'status', 'refund_amount', 'reason', 'text_reason', 'due_at', 'requested_at', 'tracking_number']),
        ];
    }

    /**
     * Biaya per Konversi of the ads promoting this order's products, exactly as the ads page / Seller Centre show it
     * (ad spend ÷ ad conversions) for the order's day, and the same figure the profit report deducts per order.
     */
    private function adCost(Order $o): ?array
    {
        $day = $o->created_at->copy()->timezone(\App\Support\Dates::TZ);
        $map = \App\Support\Analytics::adConversionMap((int) $o->marketplace_shop_id, $day->copy()->startOfDay(), $day->copy()->endOfDay());
        $seen = [];
        $lines = collect($o->items ?? [])->map(function ($i) use ($map, &$seen) {
            $id = (int) ($i['item_id'] ?? 0);
            if (! isset($map[$id]) || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $a = $map[$id];

            return ['item_id' => $id, 'name' => $i['name'] ?? '', 'campaign' => $a['campaign'], 'expense' => $a['expense'], 'conversions' => $a['conversions'],
                'per_conversion' => $a['conversions'] > 0 ? (int) round($a['cost_per_conversion']) : null];
        })->filter()->values();
        if ($lines->isEmpty()) {
            return null;
        }
        $total = (int) $lines->sum(fn ($l) => $l['per_conversion'] ?? 0);

        return ['date' => $day->toDateString(), 'lines' => $lines->all(), 'per_conversion' => $total,
            'net_after_ads' => $o->escrow_amount !== null ? (int) $o->escrow_amount - $total : null];
    }

    /** HPP of what this order sold: the listing's HPP (variation, else product), else the linked Pesanin product's. */
    private function cogs(Order $o): array
    {
        $listings = \App\Models\MarketplaceListing::where('marketplace_shop_id', $o->marketplace_shop_id)
            ->whereIn('item_id', collect($o->items ?? [])->pluck('item_id')->filter()->all() ?: [0])->with('product:id,cost_price')->get();
        $lines = collect($o->items ?? [])->map(function ($i) use ($listings) {
            $l = $listings->first(fn ($x) => (int) $x->item_id === (int) ($i['item_id'] ?? 0) && (int) $x->model_id === (int) ($i['model_id'] ?? 0))
                ?? $listings->first(fn ($x) => (int) $x->item_id === (int) ($i['item_id'] ?? 0) && (int) $x->model_id === 0);
            $hpp = $l ? ((int) $l->cost_price ?: (int) $l->product?->cost_price) : 0;
            if (! $hpp && ! empty($i['product_id'])) {
                $hpp = (int) \App\Models\Product::whereKey($i['product_id'])->value('cost_price');
            }
            $qty = (int) ($i['qty'] ?? 1);

            return ['name' => $i['name'] ?? '', 'variant' => $i['variant'] ?? null, 'qty' => $qty, 'hpp' => $hpp ?: null, 'total' => $hpp ? $hpp * $qty : null];
        })->values();
        $missing = $lines->whereNull('hpp')->count();

        return ['lines' => $lines->all(), 'missing' => $missing, 'total' => $missing ? null : (int) $lines->sum('total'),
            'partial' => (int) $lines->sum('total')];
    }

    /** Cancel an unshipped order because the stock ran out (Shopee asks which products are out of stock). */
    public function cancel(Request $request, int $id)
    {
        $o = $this->order($request, $id);
        abort_unless(in_array($o->external_status, ['UNPAID', 'READY_TO_SHIP', 'PROCESSED'], true), 422, 'Pesanan ini sudah tidak bisa dibatalkan penjual.');
        $d = $request->validate(['items' => 'required|array|min:1', 'items.*.item_id' => 'required|integer', 'items.*.model_id' => 'required|integer']);
        $this->shopee(fn () => (new ShopeeClient)->call($o->marketplaceShop, 'POST', '/api/v2/order/cancel_order', [
            'order_sn' => $o->external_order_sn, 'cancel_reason' => 'OUT_OF_STOCK',
            'item_list' => collect($d['items'])->map(fn ($i) => ['item_id' => (int) $i['item_id'], 'model_id' => (int) $i['model_id']])->values()->all(),
        ]));
        $o->update(['external_status' => 'CANCELLED', 'status' => 'cancelled']);
        try {
            (new ShopeeSync)->order($o->marketplaceShop, $o->external_order_sn);
        } catch (Throwable) {
            // keep the optimistic status; the next sync reconciles
        }
        ActivityLog::record($request->user(), 'order.shopee_cancel', "Membatalkan pesanan Shopee {$o->external_order_sn} (stok habis)");

        return ['order' => $o->fresh(['marketplaceShop']), 'message' => "Pesanan {$o->external_order_sn} dibatalkan (stok habis). Penalti keterlambatan/pembatalan bisa berlaku dari Shopee."];
    }

    public function note(Request $request, int $id)
    {
        $o = $this->order($request, $id);
        $d = $request->validate(['note' => 'nullable|string|max:500']);
        $note = trim((string) ($d['note'] ?? ''));
        $this->shopee(fn () => (new ShopeeClient)->call($o->marketplaceShop, 'POST', '/api/v2/order/set_note', ['order_sn' => $o->external_order_sn, 'note' => $note]));
        $o->forceFill(['seller_note' => $note ?: null])->save();

        return ['seller_note' => $o->seller_note];
    }
}
