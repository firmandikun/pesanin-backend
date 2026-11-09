<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Support\CheckoutService;
use App\Support\WhatsApp;
use App\Support\Midtrans\Midtrans;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicCheckoutController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $product = $this->findActive($slug);
        if (! $request->boolean('preview')) {
            $product->increment('views');
        }

        return [
            'product' => $product->makeHidden(['cost_price', 'user_id', 'views', 'sku', 'user', 'responsible_user_id']),
            'payment_methods' => CheckoutService::paymentMethods($product),
            'fields' => \App\Support\ProductForm::fields($product),
            'custom_fields' => \App\Support\ProductForm::customFields($product),
            'bump_items' => \App\Support\ProductForm::bumpItems($product),
            'sold' => \App\Support\ProductForm::component($product, 'sold', false)
                ? (int) $product->orders()->where('is_abandoned', false)->sum('quantity') : null,
            'store' => [
                'store_name' => $product->user->store_name,
                'cod_fee_percent' => $product->user->cod_fee_percent,
                'origin' => $product->user->pickupAddress()?->region?->city,
            ],
        ];
    }

    public function regions(Request $request)
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 3) {
            return [];
        }

        // Use the Biteship key of the shop being bought from (checkout) or of the signed-in seller (settings, input order).
        $seller = $request->query('seller') ? Product::where('slug', $request->query('seller'))->first()?->user : auth('sanctum')->user();

        $integrations = $seller?->integrations()->where('status', 'active')->get()->keyBy('provider');

        return \App\Support\Regions::search($q, $integrations['biteship'] ?? null, $integrations['rajaongkir'] ?? null);
    }

    public function quote(Request $request, string $slug)
    {
        $product = $this->findActive($slug);

        return CheckoutService::quote($product, $request->only(['quantity', 'variant', 'bump_items', 'region_id', 'courier', 'payment_method', 'coupon_code', 'phone']));
    }

    /** Save partial buyer data so the seller can follow up abandoned checkouts. */
    public function abandoned(Request $request, string $slug)
    {
        $product = $this->findActive($slug);
        $data = $request->validate([
            'checkout_token' => 'required|string|size:32',
            'customer_name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'region_id' => 'nullable|exists:regions,id',
            'variant' => 'nullable|string',
            'quantity' => 'nullable|integer|min:1',
        ]);
        if (empty($data['phone']) && empty($data['customer_name'])) {
            return response()->noContent();
        }

        $order = Order::firstOrNew(['checkout_token' => $data['checkout_token'], 'product_id' => $product->id]);
        if ($order->exists && ! $order->is_abandoned) {
            return response()->noContent();
        }
        $quote = CheckoutService::quote($product, $data);
        $region = ! empty($data['region_id']) ? Region::find($data['region_id']) : null;
        $order->forceFill($data + [
            'user_id' => $product->user_id,
            'is_abandoned' => true,
            'quantity' => $data['quantity'] ?? $product->min_purchase,
            'city' => $region?->city,
            'district' => $region?->district,
            'province' => $region?->province,
            'unit_price' => $quote['unit_price'],
            'subtotal' => $quote['subtotal'],
            'total' => $quote['subtotal'],
            'ip_address' => $request->ip(),
        ])->save();

        return response()->noContent();
    }

    public function checkout(Request $request, string $slug)
    {
        $product = $this->findActive($slug);
        $data = $request->validate(CheckoutService::rules($product), CheckoutService::messages());
        $this->guardBanned($product, $request, $data['phone']);

        $order = CheckoutService::place($product, $data, ['ip_address' => $request->ip(), 'source' => 'checkout']);

        // E-Payment through the seller's Midtrans: hosted payment page, result arrives via notification.
        if ($order->payment_method === 'epayment' && $mt = Midtrans::forSeller($product->user->integration('midtrans'))) {
            try {
                $snap = $mt->forOrder($order, $product->form_settings['payment']['epayment']['channels'] ?? null,
                    rtrim(config('app.frontend_url'), '/')."/c/{$slug}?order={$order->code}");
                $order->forceFill(['payment_url' => $snap['redirect_url'], 'payment_ref' => $snap['token']])->save();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Midtrans snap {$order->code}: {$e->getMessage()}");
            }
        }

        return response()->json(self::successPayload($product, $order), 201);
    }

    /** Buyer comes back from the Midtrans page: minimal, non-personal order status by code. */
    public function paymentStatus(string $code)
    {
        $order = Order::with('user.integrations')->where('code', $code)->where('payment_method', 'epayment')->firstOrFail();
        // Don't wait only for the notification: ask Midtrans directly (also covers local dev without a public URL).
        if ($order->payment_status !== 'paid' && $order->status === 'pending' && $mt = Midtrans::forSeller($order->user->integration('midtrans'))) {
            try {
                $st = $mt->status($order->code);
                if (in_array((string) ($st['status_code'] ?? ''), ['200', '201', '202', '407'], true) && ($st['order_id'] ?? null) === $order->code) {
                    Midtrans::applyToOrder($order, $st);
                }
            } catch (\Throwable) {
                // notification will still arrive
            }
        }

        return [
            'code' => $order->code, 'total' => $order->total, 'payment_status' => $order->payment_status,
            'status' => $order->status, 'payment_url' => $order->payment_status === 'paid' ? null : $order->payment_url,
            // Digital product: open it right away from the payment page once paid.
            'access_url' => $order->payment_status === 'paid' && $order->product?->type === 'digital' ? \App\Support\OrderAutomation::accessUrl($order) : null,
        ];
    }

    public function digitalFile(string $token)
    {
        $order = Order::with('product')->where('digital_token', $token)->firstOrFail();
        $c = $order->product?->digital_content ?? [];
        abort_unless($order->product?->type === 'digital' && $order->payment_status === 'paid' && ! in_array($order->status, ['cancelled', 'refunded'], true) && ! empty($c['file']), 404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($c['file']), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($c['file'], $c['file_name'] ?? basename($c['file']));
    }

    /** Buyer's access page for a paid digital product (link from WhatsApp / email / payment page). */
    public function digital(string $token)
    {
        $order = Order::with(['product', 'user'])->where('digital_token', $token)->firstOrFail();
        abort_unless($order->product?->type === 'digital', 404);
        $paid = $order->payment_status === 'paid' && ! in_array($order->status, ['cancelled', 'refunded'], true);
        $c = $order->product->digital_content ?? [];

        return [
            'store_name' => $order->user->store_name, 'product' => $order->product->name, 'image' => $order->product->images[0] ?? null,
            'customer_name' => $order->customer_name, 'code' => $order->code, 'paid' => $paid,
            'content' => $paid ? ['url' => $c['url'] ?? null, 'has_file' => ! empty($c['file']), 'file_name' => $c['file_name'] ?? null, 'instructions' => $c['instructions'] ?? null] : null,
            'whatsapp' => $order->user->whatsapp,
        ];
    }

    /** What the buyer sees after submitting: content per payment method + after-submit action. */
    public static function successPayload(Product $product, Order $order): array
    {
        $settings = $product->success_settings ?? [];
        $seller = $product->user;
        $redirectTpl = $seller->followupTemplates()->where('event', 'redirect')->value('message');
        $waTarget = $settings['whatsapp'] ?? $seller->whatsapp;

        return [
            'order' => $order->only(['code', 'customer_name', 'variant', 'quantity', 'unit_price', 'bump_price', 'subtotal', 'discount', 'coupon_code',
                'shipping_cost', 'cod_fee', 'total', 'payment_method', 'courier', 'courier_service', 'shipping_etd']),
            'bank_accounts' => $order->payment_method === 'bank_transfer'
                ? array_values(array_filter($seller->bank_accounts ?? [], fn ($b) => ! in_array($b['number'], $product->form_settings['payment']['disabled_banks'] ?? [], true)))
                : [],
            'message' => $settings['messages'][$order->payment_method] ?? null,
            'action' => ($settings['action'] ?? 'page') === 'whatsapp_random' ? 'whatsapp' : ($settings['action'] ?? 'page'),
            'redirect_url' => $settings['redirect_url'] ?? null,
            'whatsapp_url' => $waTarget && $redirectTpl ? WhatsApp::link($waTarget, WhatsApp::fill($redirectTpl, $order)) : null,
            'payment_url' => $order->payment_status === 'paid' ? null : $order->payment_url,
        ];
    }

    private function guardBanned(Product $product, Request $request, string $phone): void
    {
        $banned = $product->user->bannedDevices()
            ->where(fn ($q) => $q->where(['type' => 'ip', 'value' => $request->ip()])
                ->orWhere(fn ($qq) => $qq->where('type', 'phone')->where('value', WhatsApp::normalize($phone))))
            ->exists();
        if ($banned) {
            throw ValidationException::withMessages(['phone' => 'Pesanan tidak dapat diproses.']);
        }
    }

    private function findActive(string $slug): Product
    {
        return Product::with('user')->where('slug', $slug)->where('status', 'active')->firstOrFail();
    }
}
