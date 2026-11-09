<?php

namespace App\Support;

use App\Models\Order;

class WhatsApp
{
    public const PAYMENT_LABELS = ['bank_transfer' => 'Bank Transfer', 'cod' => 'COD (Bayar di Tempat)', 'epayment' => 'E-Payment'];

    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }

    public static function link(?string $phone, string $message): string
    {
        return 'https://wa.me/'.self::normalize($phone).'?text='.rawurlencode($message);
    }

    public static function fill(string $template, Order $order): string
    {
        $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
        $seller = $order->user;
        $banks = collect($seller?->bank_accounts ?? [])
            ->map(fn ($b) => "🏦 {$b['bank']} {$b['number']} a.n. {$b['holder']}")->implode("\n");

        $vars = [
            'name' => $order->customer_name,
            'phone' => $order->phone,
            'address' => $order->address,
            'city' => $order->city,
            'district' => $order->district,
            'product_name' => $order->product?->name,
            'product_variation' => $order->variant ? "({$order->variant})" : '',
            'product_price' => $rp($order->unit_price),
            'order_quantity' => $order->quantity,
            'shipping_cost' => $rp($order->shipping_cost),
            'cod_fee' => $rp($order->cod_fee),
            'total_price' => $rp($order->total),
            'payment_method' => self::PAYMENT_LABELS[$order->payment_method] ?? '-',
            'order_id' => $order->code,
            'courier' => trim("{$order->courier} {$order->courier_service}"),
            'receipt_number' => $order->tracking_number,
            'bank_accounts' => $banks ?: '-',
            'seller_name' => $seller?->store_name,
        ];

        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? $m[0]), $template);
    }
}
