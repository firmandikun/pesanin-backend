<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;

/** Resolves the coupon (typed code or automatic) for a checkout and computes its discount. */
class CouponService
{
    /** @return array{coupon: ?Coupon, discount: int, shipping_discount: int, error: ?string} */
    public static function apply(Product $product, ?string $code, int $subtotal, int $qty, int $shipping, ?string $paymentMethod, ?string $phone = null): array
    {
        $none = ['coupon' => null, 'discount' => 0, 'shipping_discount' => 0, 'error' => null];
        $now = now();
        $base = Coupon::where('user_id', $product->user_id)->where('is_active', true)->where('product_type', $product->type)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now));

        $coupon = $code
            ? (clone $base)->where('method', 'code')->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first()
            : (clone $base)->where('method', 'auto')->orderByDesc('value')->get()->first(fn ($c) => self::eligible($c, $product, $subtotal, $qty, $paymentMethod, $phone) === null);

        if (! $coupon) {
            return $code ? ['error' => 'Kode kupon tidak ditemukan atau sudah tidak berlaku.'] + $none : $none;
        }
        if ($err = self::eligible($coupon, $product, $subtotal, $qty, $paymentMethod, $phone)) {
            return ['error' => $err] + $none;
        }

        $target = $coupon->kind === 'shipping' ? $shipping : $subtotal;
        $amount = $coupon->value_type === 'percent' ? (int) round($target * min(100, $coupon->value) / 100) : (int) $coupon->value;
        $amount = min($amount, $target);

        return [
            'coupon' => $coupon,
            'discount' => $coupon->kind === 'shipping' ? 0 : $amount,
            'shipping_discount' => $coupon->kind === 'shipping' ? $amount : 0,
            'error' => null,
        ];
    }

    private static function eligible(Coupon $c, Product $product, int $subtotal, int $qty, ?string $pm, ?string $phone): ?string
    {
        if ($c->kind === 'product' && $c->product_ids && ! in_array($product->id, $c->product_ids)) {
            return 'Kupon tidak berlaku untuk produk ini.';
        }
        if ($c->payment_type === 'cod' && $pm !== 'cod') {
            return 'Kupon hanya berlaku untuk pembayaran COD.';
        }
        if ($c->payment_type === 'non_cod' && $pm === 'cod') {
            return 'Kupon tidak berlaku untuk pembayaran COD.';
        }
        if ($c->min_type === 'amount' && $subtotal < $c->min_value) {
            return 'Minimal belanja Rp'.number_format($c->min_value, 0, ',', '.').' untuk kupon ini.';
        }
        if ($c->min_type === 'qty' && $qty < $c->min_value) {
            return "Minimal {$c->min_value} item untuk kupon ini.";
        }
        if ($c->max_uses && $c->used_count >= $c->max_uses) {
            return 'Kuota kupon sudah habis.';
        }
        if ($c->once_per_customer && $phone && Order::where('user_id', $c->user_id)->where('coupon_code', $c->code)->where('phone', $phone)->where('is_abandoned', false)->exists()) {
            return 'Kupon ini sudah pernah kamu gunakan.';
        }

        return null;
    }
}
