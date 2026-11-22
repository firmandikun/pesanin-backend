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
                ['label' => 'Performa Produk', 'href' => '/marketplace/product-performance'],
                ['label' => 'Cek Margin', 'href' => '/marketplace/margins'],
                ['label' => 'Stok Master', 'href' => '/marketplace/stock'],
                ['label' => 'Pesanan & Pengiriman', 'href' => '/marketplace/orders'],
                ['label' => 'Scan Out', 'href' => '/marketplace/scan-out'],
                ['label' => 'Promosi', 'href' => '/marketplace/promotions'],
                ['label' => 'Ulasan', 'href' => '/marketplace/reviews'],
                ['label' => 'Kesehatan Akun', 'href' => '/marketplace/health'],
                ['label' => 'Saldo', 'href' => '/marketplace/balance'],
                ['label' => 'Cek Dana', 'href' => '/marketplace/money-check'],
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
                ['label' => 'Diagnosa Bisnis AI', 'href' => '/reports/ai-diagnosis'],
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

    /** Menus that belong to one track (GTM Playbook v2.0). Not listed = shown in both tracks. */
    public const TRACK = ['products' => 'social', 'dropship' => 'social', 'orders' => 'social', 'operations' => 'social', 'promotions' => 'social', 'marketplace' => 'marketplace'];

    /** Single menu entries inside shared groups that belong to one track, by link. */
    public const CHILD_TRACK = [
        '/broadcast' => 'social', '/broadcast/templates' => 'social', '/reports/meta-ads' => 'social', '/others/product-reports' => 'social',
        '/reports/research' => 'marketplace', '/reports/market' => 'marketplace',
        '/settings/addresses' => 'social', '/settings/tracking' => 'social', '/settings/domains' => 'social', '/settings/banned' => 'social',
    ];

    /** Menu entries that need a plan feature (shown with the plan that unlocks it). */
    public const FEATURE_LINKS = ['/marketplace/stock' => 'stock_master', '/settings/tracking' => 'pixels', '/others/coupons' => 'coupons', '/affiliate' => 'affiliates'];

    /** Menu entries that need a quota above 0 (e.g. custom domain from Starter Social & Web). */
    public const QUOTA_LINKS = ['/settings/domains' => 'domains', '/others/team-members' => 'team'];

    /**
     * The sidebar for one store: only its track's menus, both tracks with the add-on or Enterprise.
     * @return array{items: array, hidden: string[]} hidden = links of the other track (pages blocked in the shell)
     */
    public static function forUser(\App\Models\User $user): array
    {
        $items = self::visible();
        if (Plans::hasAddon($user)) {
            return ['items' => self::withPlanBadges($user, $items), 'hidden' => []];
        }
        $track = Plans::track($user);
        $hidden = [];
        $out = [];
        foreach ($items as $item) {
            $links = array_values(array_filter(array_merge([$item['href'] ?? null], array_column($item['children'] ?? [], 'href'))));
            if (($t = self::TRACK[$item['key']] ?? null) && $t !== $track) {
                array_push($hidden, ...$links);

                continue;
            }
            if (! empty($item['children'])) {
                $item['children'] = array_values(array_filter($item['children'], function ($c) use ($track, &$hidden) {
                    $t = self::CHILD_TRACK[$c['href']] ?? null;
                    if ($t && $t !== $track) {
                        $hidden[] = $c['href'];

                        return false;
                    }

                    return true;
                }));
            }
            $out[] = $item;
        }

        return ['items' => self::withPlanBadges($user, $out), 'hidden' => array_values(array_unique(array_map(fn ($h) => strtok($h, '?'), array_filter($hidden, fn ($h) => str_starts_with($h, '/')))))];
    }

    /** Mark entries the plan does not include yet with the plan that unlocks them ("Starter", "Bisnis", …). */
    private static function withPlanBadges(\App\Models\User $user, array $items): array
    {
        $plan = Plans::forUser($user);
        $catalog = Plans::all($plan['track']);
        $need = function (string $href) use ($user, $plan, $catalog): ?string {
            if (($f = self::FEATURE_LINKS[$href] ?? null) && ! Plans::hasFeature($user, $f)) {
                return $catalog[Plans::features()[$f]]['name'] ?? null;
            }
            if (($q = self::QUOTA_LINKS[$href] ?? null) && ($plan['limits'][$q] ?? null) !== null && $plan['limits'][$q] <= ($q === 'team' ? 1 : 0)) {
                foreach ($catalog as $p) {
                    if ($p['limits'][$q] === null || $p['limits'][$q] > ($q === 'team' ? 1 : 0)) {
                        return $p['name'];
                    }
                }
            }

            return null;
        };

        return array_map(function ($item) use ($need) {
            if (! empty($item['children'])) {
                $item['children'] = array_map(fn ($c) => ($b = $need($c['href'])) ? $c + ['lock' => $b] : $c, $item['children']);
            }

            return $item;
        }, $items);
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
