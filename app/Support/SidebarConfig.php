<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Seller sidebar menu definitions + DB-backed visibility managed from the Admin Console.
 * Returned via `/api/me`, `/api/sidebar`, `/api/public/site`, and `/api/admin/platform`.
 */
class SidebarConfig
{
    /** Default seller navigation tree; icon names map to lucide-react components in the frontend shell. */
    public const ITEMS = [
        [
            'key' => 'whats_new',
            'label' => 'Info Terbaru',
            'description' => 'Pengumuman fitur dan update terbaru Pesanin',
            'icon' => 'Megaphone',
            'href' => '#whats-new',
            'locked' => false,
            'default_visible' => true,
        ],
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'description' => 'Ringkasan performa toko (selalu aktif)',
            'icon' => 'LayoutDashboard',
            'href' => '/dashboard',
            'locked' => true,
            'default_visible' => true,
        ],
        [
            'key' => 'products',
            'label' => 'Produk',
            'description' => 'Daftar produk fisik dan digital milik seller',
            'icon' => 'Package',
            'href' => '/products',
            'locked' => false,
            'default_visible' => true,
        ],
        [
            'key' => 'dropship',
            'label' => 'Dropship Pesanin',
            'description' => 'Katalog produk fisik & digital siap dropship beserta konten dan landing page',
            'icon' => 'Boxes',
            'href' => '/dropship',
            'locked' => false,
            'default_visible' => true,
        ],
        [
            'key' => 'orders',
            'label' => 'Pesanan Media Sosial',
            'description' => 'Pesanan checkout web, abandoned cart, dan konfirmasi pembayaran',
            'icon' => 'ClipboardCheck',
            'locked' => false,
            'default_visible' => true,
            'children' => [
                ['label' => 'Semua Pesanan Medsos', 'href' => '/orders'],
                ['label' => 'Checkout Ditinggalkan', 'href' => '/orders?view=abandoned'],
                ['label' => 'Diarsipkan', 'href' => '/orders?view=archived'],
                ['label' => 'Konfirmasi Pembayaran', 'href' => '/others/payments'],
            ],
        ],
        [
            'key' => 'chat',
            'label' => 'Chat',
            'description' => 'Kotak masuk, broadcast WhatsApp, pelanggan, dan anggota tim',
            'icon' => 'MessageCircle',
            'locked' => false,
            'default_visible' => true,
            'children' => [
                ['label' => 'Kotak Masuk', 'href' => '/chat'],
                ['label' => 'Broadcast', 'href' => '/broadcast'],
                ['label' => 'Template WhatsApp', 'href' => '/broadcast/templates'],
                ['label' => 'Pelanggan', 'href' => '/customers'],
                ['label' => 'Balasan Cepat & Label', 'href' => '/chat/quick-replies'],
                ['label' => 'Team Members', 'href' => '/others/team-members'],
            ],
        ],
        [
            'key' => 'marketplace',
            'label' => 'Marketplace',
            'description' => 'Integrasi toko Shopee, daftar produk, pesanan, dan saldo marketplace',
            'icon' => 'Store',
            'locked' => false,
            'default_visible' => true,
            'children' => [
                ['label' => 'Daftar Toko', 'href' => '/marketplace/shops'],
                ['label' => 'Semua Produk', 'href' => '/marketplace/products'],
                ['label' => 'Stok Master', 'href' => '/marketplace/stock'],
                ['label' => 'Pesanan & Pengiriman', 'href' => '/marketplace/orders'],
                ['label' => 'Scan Out', 'href' => '/marketplace/scan-out'],
                ['label' => 'Promosi', 'href' => '/marketplace/promotions'],
                ['label' => 'Ulasan', 'href' => '/marketplace/reviews'],
                ['label' => 'Kesehatan Akun', 'href' => '/marketplace/health'],
                ['label' => 'Saldo', 'href' => '/marketplace/balance'],
            ],
        ],
        [
            'key' => 'reports',
            'label' => 'Laporan Bisnis',
            'description' => 'Laporan penjualan, laba bersih, pengeluaran, iklan, dan riset',
            'icon' => 'BarChart3',
            'locked' => false,
            'default_visible' => true,
            'children' => [
                ['label' => 'Laporan Penjualan', 'href' => '/reports/sales'],
                ['label' => 'Statistik Penjualan', 'href' => '/reports/statistics'],
                ['label' => 'Laba Bersih', 'href' => '/reports/profit'],
                ['label' => 'Pengeluaran', 'href' => '/reports/expenses'],
                ['label' => 'Laporan Produk', 'href' => '/others/product-reports'],
                ['label' => 'Biaya Iklan', 'href' => '/reports/ads'],
                ['label' => 'Iklan Meta', 'href' => '/reports/meta-ads'],
                ['label' => 'Riset Produk', 'href' => '/reports/research'],
                ['label' => 'Riset Pasar', 'href' => '/reports/market'],
            ],
        ],
        [
            'key' => 'operations',
            'label' => 'Operasional Media Sosial',
            'description' => 'Inventori, transaksi media sosial, dan saldo',
            'icon' => 'Wallet',
            'locked' => false,
            'default_visible' => true,
            'children' => [
                ['label' => 'Inventori Medsos', 'href' => '/others/inventory'],
                ['label' => 'Transaksi Medsos', 'href' => '/others/transactions'],
                ['label' => 'Saldo', 'href' => '/settings/balance'],
            ],
        ],
        [
            'key' => 'promotions',
            'label' => 'Promosi',
            'description' => 'Landing Page, kupon diskon, afiliasi, dan follow up',
            'icon' => 'Gift',
            'locked' => false,
            'default_visible' => true,
            'children' => [
                ['label' => 'Landing Page', 'href' => '/landing-pages'],
                ['label' => 'Laporan Landing Page', 'href' => '/landing-pages/reports'],
                ['label' => 'Kupon', 'href' => '/others/coupons'],
                ['label' => 'Afiliasi', 'href' => '/affiliate'],
                ['label' => 'Follow Up Manual', 'href' => '/settings/followup'],
            ],
        ],
        [
            'key' => 'settings',
            'label' => 'Pengaturan',
            'description' => 'Pengaturan akun, paket, integrasi, dan toko (selalu aktif)',
            'icon' => 'Settings',
            'locked' => true,
            'default_visible' => true,
            'children' => [
                ['label' => 'Akun', 'href' => '/settings/account'],
                ['label' => 'Paket & Tagihan', 'href' => '/settings/billing'],
                ['label' => 'Integrasi', 'href' => '/settings/integrations'],
                ['label' => 'Alamat Pickup', 'href' => '/settings/addresses'],
                ['label' => 'Otomatisasi Pesanan', 'href' => '/settings/automation'],
                ['label' => 'Cetak Resi & Label', 'href' => '/settings/print'],
                ['label' => 'Notifikasi', 'href' => '/settings/notifications'],
                ['label' => 'Tracking Pixel', 'href' => '/settings/tracking'],
                ['label' => 'Custom Domain', 'href' => '/settings/domains'],
                ['label' => 'Perangkat Diblokir', 'href' => '/settings/banned'],
                ['label' => 'Log Aktivitas', 'href' => '/settings/activity'],
            ],
        ],
        [
            'key' => 'tutorial',
            'label' => 'Tutorial',
            'description' => 'Panduan dan video tutorial penggunaan Pesanin',
            'icon' => 'BookOpen',
            'href' => '/tutorial',
            'locked' => false,
            'default_visible' => true,
        ],
    ];

    /** All menus with their live `visible` state (used by Admin Console & Seller Shell). */
    public static function all(): array
    {
        $saved = PlatformSetting::read('sidebar', []);
        if (! is_array($saved)) {
            $saved = [];
        }

        return array_map(function (array $item) use ($saved) {
            $visible = ! empty($item['locked'])
                ? true
                : (array_key_exists($item['key'], $saved) ? (bool) $saved[$item['key']] : (bool) $item['default_visible']);

            return array_merge($item, ['visible' => $visible]);
        }, self::ITEMS);
    }

    /** Only enabled menus for the Seller sidebar response. */
    public static function visible(): array
    {
        return array_values(array_filter(self::all(), fn (array $item) => $item['visible']));
    }

    public static function isEnabled(string $key): bool
    {
        foreach (self::all() as $item) {
            if ($item['key'] === $key) {
                return (bool) $item['visible'];
            }
        }

        return true;
    }

    /** Valid toggleable keys map (`key => bool`). */
    public static function sanitizeOverrides(array $input): array
    {
        $valid = [];
        foreach (self::ITEMS as $item) {
            if (! empty($item['locked'])) {
                continue;
            }
            if (array_key_exists($item['key'], $input)) {
                $valid[$item['key']] = (bool) $input[$item['key']];
            }
        }

        return $valid;
    }
}
