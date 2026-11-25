<?php

namespace App\Support;

use App\Models\Product;
use App\Support\Ai\Gemini;
use Illuminate\Support\Str;

/** Generates a structured Pesanin LandingPage (blocks, theme, seo) using Gemini AI and existing builder components. */
class LandingPageAi
{
    public const BLOCK_TYPES = [
        'announcement', 'headline', 'subheadline', 'text', 'image', 'slider', 'video', 'button',
        'whatsapp', 'features', 'testimonials', 'faq', 'countdown', 'pricing', 'checkout', 'divider', 'spacer',
    ];

    /**
     * @return array{title: string, theme: array, seo: array, blocks: array, model: string}
     */
    public static function generate(
        string $apiKey,
        ?Product $product,
        ?string $title = null,
        ?string $brief = null,
        string $tone = 'persuasif',
        string $preview = 'mobile',
        ?string $storeName = null,
    ): array {
        $prompt = self::buildPrompt($product, $title, $brief, $tone, $storeName);
        $raw = Gemini::json($apiKey, $prompt, ['title', 'blocks'], 0.45);

        $finalTitle = trim((string) ($title ?: ($raw['title'] ?? $product?->name ?? 'Landing Page Baru')));
        $finalTitle = Str::limit($finalTitle, 145, '');

        $rawPrimary = (string) ($raw['theme']['primary'] ?? '#e05510');
        $primary = preg_match('/^#[0-9a-fA-F]{6}$/', $rawPrimary) ? $rawPrimary : '#e05510';
        $theme = [
            'primary' => $primary,
            'background' => '#ffffff',
            'width' => $preview === 'desktop' ? 'wide' : 'narrow',
        ];

        $seo = [
            'title' => Str::limit(trim((string) ($raw['seo']['title'] ?? $finalTitle)), 115, ''),
            'description' => Str::limit(trim((string) ($raw['seo']['description'] ?? $product?->description ?? '')), 290, ''),
        ];

        $blocks = self::sanitizeBlocks($raw['blocks'] ?? [], $product);

        return [
            'title' => $finalTitle,
            'theme' => $theme,
            'seo' => $seo,
            'blocks' => $blocks,
            'model' => (string) ($raw['model'] ?? 'gemini'),
        ];
    }

    private static function buildPrompt(?Product $product, ?string $title, ?string $brief, string $tone, ?string $storeName): string
    {
        $productCtx = $product ? json_encode([
            'id' => $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'description' => $product->description,
            'price' => (int) $product->price,
            'sale_price' => $product->sale_price ? (int) $product->sale_price : null,
            'selling_price' => (int) $product->sellingPrice(),
            'features' => $product->features ?? [],
            'variants' => $product->variants ?? [],
            'image_count' => is_array($product->images) ? count($product->images) : 0,
        ], JSON_UNESCAPED_UNICODE) : 'null (tidak memilih produk spesifik, buat berdasarkan brief)';

        $toneDesc = match ($tone) {
            'elegan' => 'elegan, premium, berkelas, bahasa Indonesia rapi',
            'santai' => 'santai, akrab, relatable, bahasa Indonesia sehari-hari yang menarik',
            'profesional' => 'profesional, informatif, terpercaya, berbasis manfaat nyata',
            default => 'persuasif, fokus konversi (high-converting), jelas, memakai formula hook-problem-solution-proof-CTA dalam bahasa Indonesia',
        };

        $titleHint = $title ? "Judul wajib/referensi: {$title}" : 'Buat judul halaman yang singkat dan menarik.';
        $briefHint = $brief ? "Brief/catatan dari penjual: {$brief}" : 'Tidak ada catatan tambahan.';
        $storeHint = $storeName ? "Nama toko: {$storeName}" : '';

        return <<<PROMPT
Kamu adalah copywriter & conversion designer landing page Indonesia terbaik.
Tugasmu: buat struktur landing page lengkap dalam format JSON menggunakan HANYA komponen blok yang tersedia di Pesanin Page Builder.

Konteks:
- {$storeHint}
- {$titleHint}
- Gaya bahasa: {$toneDesc}
- {$briefHint}
- Data Produk: {$productCtx}

Daftar tipe blok (`type`) dan struktur `data` yang VALID (JANGAN gunakan tipe lain di luar daftar ini):
1. "announcement": {"text": "string promo singkat di paling atas"}
2. "headline": {"text": "string judul besar", "align": "center" | "left", "size": "xl" | "lg"}
3. "subheadline": {"text": "string subjudul pendukung", "align": "center" | "left"}
4. "text": {"content": "string paragraf cerita/masalah/solusi (boleh pakai \\n\\n untuk paragraf baru)"}
5. "image": {"url": "", "alt": "deskripsi gambar"}
6. "slider": {"images": []}  (pakai jika produk punya lebih dari 1 foto)
7. "features": {"title": "string judul bagian", "items": ["poin 1", "poin 2", "poin 3", "poin 4"]}
8. "testimonials": {"title": "Kata Mereka", "items": [{"name": "Nama, Kota", "text": "testimoni realistis", "rating": 5}]}
9. "pricing": {"title": "Harga Spesial Hari Ini", "normal": 199000, "price": 99000, "note": "catatan promo singkat"}
10. "countdown": {"label": "Promo berakhir dalam", "until": ""}
11. "button": {"label": "Pesan Sekarang", "url": "#order"}
12. "whatsapp": {"label": "Tanya via WhatsApp", "number": "", "message": "Halo, saya tertarik dengan produk ini"}
13. "checkout": {"product_id": null, "title": "Pesan Sekarang"}
14. "faq": {"title": "Pertanyaan yang Sering Ditanyakan", "items": [{"q": "pertanyaan", "a": "jawaban"}]}
15. "divider": {}
16. "spacer": {"size": 24}

Aturan penting:
- Susun alur landing page yang enak dibaca & menjual (8–13 blok): announcement → headline → subheadline → image/slider → text (masalah & solusi) → features → testimonials → pricing → countdown → button/checkout → faq → whatsapp.
- Jika Data Produk ada, gunakan nama, manfaat, dan harga asli produk tersebut. Jangan mengarang klaim medis/legal yang berlebihan.
- Kembalikan JSON valid saja dengan struktur persis seperti ini:
{
  "title": "Judul Halaman",
  "theme": {"primary": "#e05510"},
  "seo": {"title": "Judul SEO (maks 60 karakter)", "description": "Deskripsi SEO singkat (maks 155 karakter)"},
  "blocks": [
    {"type": "announcement", "data": {...}},
    ...
  ]
}
PROMPT;
    }

    /** Clean & normalize AI-generated blocks to match BlockRenderer/BlockEditor schemas. */
    private static function sanitizeBlocks(mixed $rawBlocks, ?Product $product): array
    {
        if (! is_array($rawBlocks)) {
            $rawBlocks = [];
        }

        $images = is_array($product?->images) ? array_values(array_filter($product->images)) : [];
        $primaryImage = $images[0] ?? '';
        $price = $product ? (int) $product->sellingPrice() : 99000;
        $normal = $product && $product->sale_price ? (int) $product->price : null;
        $untilDefault = now()->addDays(2)->format('Y-m-d\TH:i');

        $out = [];
        $hasCheckout = false;
        $usedMainImage = false;

        foreach ($rawBlocks as $b) {
            if (! is_array($b)) {
                continue;
            }
            $type = (string) ($b['type'] ?? '');
            if (! in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }
            $d = is_array($b['data'] ?? null) ? $b['data'] : [];

            $clean = match ($type) {
                'announcement' => ['text' => (string) ($d['text'] ?? 'PROMO TERBATAS HARI INI')],
                'headline' => [
                    'text' => (string) ($d['text'] ?? ($product?->name ?? 'Judul Utama')),
                    'align' => in_array($d['align'] ?? '', ['left', 'center', 'right'], true) ? $d['align'] : 'center',
                    'size' => in_array($d['size'] ?? '', ['md', 'lg', 'xl'], true) ? $d['size'] : 'xl',
                ],
                'subheadline' => [
                    'text' => (string) ($d['text'] ?? ''),
                    'align' => in_array($d['align'] ?? '', ['left', 'center', 'right'], true) ? $d['align'] : 'center',
                ],
                'text' => ['content' => (string) ($d['content'] ?? '')],
                'image' => (function () use ($d, $primaryImage, $product, &$usedMainImage) {
                    $url = trim((string) ($d['url'] ?? ''));
                    if ($url === '' && ! $usedMainImage && $primaryImage !== '') {
                        $url = $primaryImage;
                        $usedMainImage = true;
                    }

                    return ['url' => $url, 'alt' => (string) ($d['alt'] ?? $product?->name ?? '')];
                })(),
                'slider' => [
                    'images' => count($images) > 1 ? $images : (is_array($d['images'] ?? null) ? array_values($d['images']) : ($primaryImage !== '' ? [$primaryImage] : [])),
                ],
                'video' => ['url' => (string) ($d['url'] ?? '')],
                'button' => [
                    'label' => (string) ($d['label'] ?? 'Pesan Sekarang'),
                    'url' => (string) ($d['url'] ?? '#order'),
                ],
                'whatsapp' => [
                    'label' => (string) ($d['label'] ?? 'Tanya via WhatsApp'),
                    'number' => (string) ($d['number'] ?? ''),
                    'message' => (string) ($d['message'] ?? ('Halo, saya mau tanya tentang '.($product?->name ?? 'produk ini'))),
                ],
                'features' => [
                    'title' => (string) ($d['title'] ?? 'Keunggulan'),
                    'items' => array_values(array_filter(array_map(fn ($x) => is_string($x) ? trim($x) : '', is_array($d['items'] ?? null) ? $d['items'] : []))),
                ],
                'testimonials' => [
                    'title' => (string) ($d['title'] ?? 'Kata Mereka'),
                    'items' => array_values(array_filter(array_map(fn ($x) => is_array($x) ? [
                        'name' => (string) ($x['name'] ?? 'Pelanggan'),
                        'text' => (string) ($x['text'] ?? ''),
                        'rating' => max(1, min(5, (int) ($x['rating'] ?? 5))),
                    ] : null, is_array($d['items'] ?? null) ? $d['items'] : []))),
                ],
                'faq' => [
                    'title' => (string) ($d['title'] ?? 'FAQ'),
                    'items' => array_values(array_filter(array_map(fn ($x) => is_array($x) ? [
                        'q' => (string) ($x['q'] ?? ''),
                        'a' => (string) ($x['a'] ?? ''),
                    ] : null, is_array($d['items'] ?? null) ? $d['items'] : []))),
                ],
                'pricing' => [
                    'title' => (string) ($d['title'] ?? 'Harga Spesial'),
                    'normal' => $product ? $normal : (isset($d['normal']) && is_numeric($d['normal']) ? (int) $d['normal'] : null),
                    'price' => $product ? $price : (isset($d['price']) && is_numeric($d['price']) ? (int) $d['price'] : $price),
                    'note' => (string) ($d['note'] ?? ''),
                ],
                'countdown' => [
                    'label' => (string) ($d['label'] ?? 'Promo berakhir dalam'),
                    'until' => ! empty($d['until']) ? (string) $d['until'] : $untilDefault,
                ],
                'checkout' => (function () use ($d, $product, &$hasCheckout) {
                    $hasCheckout = true;

                    return [
                        'product_id' => $product?->id ?? null,
                        'title' => (string) ($d['title'] ?? 'Pesan Sekarang'),
                    ];
                })(),
                'spacer' => ['size' => max(8, min(120, (int) ($d['size'] ?? 24)))],
                'divider' => [],
            };

            $out[] = LandingTemplates::block($type, $clean);
        }

        if ($product && ! $hasCheckout) {
            $out[] = LandingTemplates::block('checkout', [
                'product_id' => $product->id,
                'title' => 'Pesan Sekarang',
            ]);
        }

        return array_slice($out, 0, 80);
    }
}
