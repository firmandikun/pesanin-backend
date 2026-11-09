<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Str;

/** Starter block layouts for new landing pages. */
class LandingTemplates
{
    public const TEMPLATES = [
        'blank' => 'Blank Template',
        'simple' => 'Simple',
        'product_solution' => 'Product Solution',
        'product_fisik' => 'Product Fisik',
    ];

    public static function blocks(string $template, ?Product $product): array
    {
        $name = $product?->name ?? 'Nama Produk Kamu';
        $image = $product?->images[0] ?? null;
        $price = $product?->sellingPrice() ?? 99000;
        $normal = $product?->sale_price ? $product->price : null;
        $features = $product?->features ?: ['Keunggulan pertama produk kamu', 'Keunggulan kedua yang bikin beda', 'Garansi uang kembali'];
        $checkout = $product ? [self::block('checkout', ['product_id' => $product->id, 'title' => 'Pesan Sekarang'])] : [];

        return match ($template) {
            'simple' => [
                self::block('headline', ['text' => $name, 'align' => 'center', 'size' => 'xl']),
                self::block('subheadline', ['text' => $product?->description ?? 'Jelaskan manfaat utama produkmu dalam satu kalimat.', 'align' => 'center']),
                self::block('image', ['url' => $image, 'alt' => $name]),
                self::block('features', ['title' => 'Kenapa harus '.$name.'?', 'items' => $features]),
                ...$checkout,
            ],
            'product_solution' => [
                self::block('announcement', ['text' => 'PROMO TERBATAS — Gratis ongkir hari ini!']),
                self::block('headline', ['text' => 'Masih bingung cari solusi untuk masalahmu?', 'align' => 'center', 'size' => 'xl']),
                self::block('text', ['content' => "Banyak orang mengalami masalah yang sama. Sudah coba berbagai cara tapi hasilnya belum memuaskan.\n\nKenalkan, {$name} — solusi praktis yang sudah dipercaya ribuan pelanggan."]),
                self::block('image', ['url' => $image, 'alt' => $name]),
                self::block('features', ['title' => 'Manfaat yang kamu dapat', 'items' => $features]),
                self::block('testimonials', ['title' => 'Kata mereka', 'items' => [
                    ['name' => 'Siti, Jakarta', 'text' => 'Produknya bagus banget, pengiriman cepat!', 'rating' => 5],
                    ['name' => 'Budi, Bandung', 'text' => 'Sudah repeat order 3x, recommended.', 'rating' => 5],
                ]]),
                self::block('pricing', ['title' => 'Harga Spesial Hari Ini', 'normal' => $normal, 'price' => $price, 'note' => 'Stok terbatas']),
                self::block('countdown', ['label' => 'Promo berakhir dalam', 'until' => now()->addDays(2)->format('Y-m-d\TH:i')]),
                ...$checkout,
                self::block('faq', ['title' => 'Pertanyaan yang sering ditanyakan', 'items' => [
                    ['q' => 'Apakah bisa COD?', 'a' => 'Bisa, bayar di tempat saat paket sampai.'],
                    ['q' => 'Berapa lama pengiriman?', 'a' => 'Estimasi 1–4 hari kerja tergantung lokasi.'],
                ]]),
            ],
            'product_fisik' => [
                self::block('image', ['url' => $image, 'alt' => $name]),
                self::block('headline', ['text' => $name, 'align' => 'left', 'size' => 'lg']),
                self::block('pricing', ['title' => '', 'normal' => $normal, 'price' => $price, 'note' => 'Gratis kapas wajah untuk 100 pembeli pertama']),
                self::block('features', ['title' => 'Detail produk', 'items' => $features]),
                self::block('whatsapp', ['label' => 'Tanya via WhatsApp', 'number' => '', 'message' => "Halo, saya mau tanya tentang {$name}"]),
                ...$checkout,
            ],
            default => [],
        };
    }

    public static function block(string $type, array $data): array
    {
        return ['id' => Str::lower(Str::random(8)), 'type' => $type, 'data' => $data];
    }
}
