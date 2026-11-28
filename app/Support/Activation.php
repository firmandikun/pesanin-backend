<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Aktivasi seller baru: the first steps that bring a new seller to the "aha" moment (seeing real profit) and into
 * daily use. One definition feeds the dashboard checklist (seller), the activation funnel (Admin Console) and the
 * reminders to sellers who stopped halfway.
 */
class Activation
{
    /**
     * Steps per track, in the order a seller should do them.
     * `nudge` is the reminder for a seller stuck on that step (null: happens by itself, e.g. orders after connecting).
     */
    public const STEPS = [
        'marketplace' => [
            'shop' => ['title' => 'Hubungkan toko Shopee', 'href' => '/marketplace/shops',
                'nudge' => 'Hubungkan toko Shopee-mu (±2 menit). Setelah itu pesanan, stok, dan laba bersih terhitung otomatis.'],
            'order' => ['title' => 'Pesanan Shopee tersinkron', 'href' => '/marketplace/orders', 'nudge' => null],
            'hpp' => ['title' => 'Isi HPP 3 produk terlaris', 'href' => '/marketplace/products?cost=empty&bulk=1',
                'nudge' => 'Isi HPP (modal) untuk 3 produk terlarismu. Tanpa HPP, laba bersih belum bisa dihitung dengan benar.'],
            'profit' => ['title' => 'Lihat laba bersih sebenarnya', 'href' => '/reports/profit', 'nudge' => null],
            'awb' => ['title' => 'Cetak resi pertama dari Pesanin', 'href' => '/marketplace/orders',
                'nudge' => 'Coba cetak resi Shopee dari Pesanin: bisa sekaligus banyak, nama produk disembunyikan, dan ada daftar SKU untuk packing.'],
            'telegram' => ['title' => 'Hubungkan Telegram', 'href' => '/settings/notifications',
                'nudge' => 'Hubungkan Telegram agar ringkasan laba setiap pagi dan peringatan penting (iklan rugi, batas kirim) langsung masuk ke HP-mu.'],
            'whatsapp' => ['title' => 'Isi nomor WhatsApp', 'href' => '/settings/account', 'nudge' => null],
        ],
        'social' => [
            'product' => ['title' => 'Tambah produk pertama', 'href' => '/products/new',
                'nudge' => 'Tambahkan produk pertamamu supaya halaman checkout & landing page bisa langsung dipakai jualan.'],
            'order' => ['title' => 'Terima pesanan pertama', 'href' => '/orders', 'nudge' => null],
            'tracking' => ['title' => 'Kirim pesanan dengan resi', 'href' => '/orders', 'nudge' => null],
            'followup' => ['title' => 'Atur pesan follow up', 'href' => '/settings/followup', 'nudge' => null],
            'address' => ['title' => 'Lengkapi alamat pickup', 'href' => '/settings/addresses',
                'nudge' => 'Lengkapi alamat pickup agar ongkir di checkout benar dan kurir bisa dipesan otomatis.'],
            'whatsapp' => ['title' => 'Isi WhatsApp & rekening', 'href' => '/settings/account',
                'nudge' => 'Isi nomor WhatsApp dan rekening toko supaya pembeli bisa transfer dan menghubungimu.'],
        ],
    ];

    /** @return array<string, bool> step key => done */
    public static function progress(User $user, ?string $track = null): array
    {
        $track ??= Plans::track($user);
        if ($track === 'social') {
            return [
                'product' => $user->products()->exists(),
                'order' => $user->orders()->where('is_abandoned', false)->exists(),
                'tracking' => $user->orders()->whereNotNull('tracking_number')->exists(),
                'followup' => $user->followupTemplates()->where('updated_at', '>', $user->created_at->copy()->addSecond())->exists(),
                'address' => $user->addresses()->exists(),
                'whatsapp' => $user->whatsapp && ! empty($user->bank_accounts),
            ];
        }
        $shop = $user->marketplaceShops()->where('is_demo', false)->where('status', 'active')->exists();
        $hpp = MarketplaceListing::where('user_id', $user->id)->where('cost_price', '>', 0)->count() >= 3;
        $order = $user->orders()->where('channel', 'shopee')->exists();
        // Queried (not the cached relation) so a bot connected a moment ago counts right away.
        $tg = $user->integrations()->where('provider', 'telegram')->where('status', 'active')->first();

        return [
            'shop' => $shop,
            'order' => $order,
            'hpp' => $hpp,
            'profit' => $shop && $hpp && $order,
            'awb' => Order::where('user_id', $user->id)->where('channel', 'shopee')->whereNotNull('awb_printed_at')->exists(),
            'telegram' => (bool) ($tg && ! empty($tg->settings['chat_id'])),
            'whatsapp' => (bool) $user->whatsapp,
        ];
    }

    /** Steps with their state, for the checklist and the admin view. */
    public static function steps(User $user, ?string $track = null): array
    {
        $track ??= Plans::track($user);
        $done = self::progress($user, $track);
        $steps = collect(self::STEPS[$track])->map(fn ($s, $k) => ['key' => $k, 'title' => $s['title'], 'href' => $s['href'], 'done' => (bool) ($done[$k] ?? false)])->values();
        $next = $steps->firstWhere('done', false);

        return ['track' => $track, 'steps' => $steps->all(), 'done' => $steps->where('done', true)->count(), 'total' => $steps->count(), 'next' => $next['key'] ?? null];
    }

    /**
     * Daily, for sellers 1–14 days old: remind them about the first step they skipped that needs their action.
     * Each step is reminded once, and a seller gets at most one reminder every 2 days.
     */
    public static function nudge(User $user): ?string
    {
        $age = $user->created_at->diffInHours(now());
        if ($age < 24 || $age > 14 * 24 || ! Alert::enabled($user, 'activation') || Cache::has("activation_nudge:recent:{$user->id}")) {
            return null;
        }
        $track = Plans::track($user);
        $done = self::progress($user, $track);
        foreach (self::STEPS[$track] as $key => $s) {
            if (($done[$key] ?? false) || ! $s['nudge']) {
                continue;
            }
            if (! Cache::add("activation_nudge:{$user->id}:{$key}", 1, now()->addDays(60))) {
                continue; // already reminded about this one
            }
            Cache::put("activation_nudge:recent:{$user->id}", 1, now()->addDays(2));
            $n = count(array_filter($done));
            Alert::send($user, 'activation', "Langkah berikutnya: {$s['title']}", $s['nudge']."\n\nProgres: {$n} dari ".count($done).' langkah selesai.', $s['href'], '👉');

            return $key;
        }

        return null;
    }

    /**
     * Admin Console: the funnel of sellers who signed up in the last $days days, and those who stopped halfway.
     */
    public static function report(int $days = 30, string $track = 'marketplace'): array
    {
        $users = User::where('is_admin', false)->whereNull('suspended_at')->where('created_at', '>=', now()->subDays($days))
            ->latest()->get()->filter(fn ($u) => Plans::track($u) === $track)->values();
        $keys = array_keys(self::STEPS[$track]);
        $funnel = array_fill_keys($keys, 0);
        $complete = 0;
        $stuck = [];
        foreach ($users as $u) {
            $done = self::progress($u, $track);
            foreach ($keys as $k) {
                $funnel[$k] += $done[$k] ? 1 : 0;
            }
            $next = collect($keys)->first(fn ($k) => ! $done[$k]);
            if ($next === null) {
                $complete++;

                continue;
            }
            $stuck[] = [
                'id' => $u->id, 'name' => $u->name, 'store_name' => $u->store_name, 'email' => $u->email, 'whatsapp' => $u->whatsapp,
                'created_at' => $u->created_at, 'days' => (int) $u->created_at->diffInDays(now()),
                'done' => count(array_filter($done)), 'next' => $next, 'next_title' => self::STEPS[$track][$next]['title'],
            ];
        }

        return [
            'days' => $days, 'track' => $track, 'signups' => $users->count(), 'complete' => $complete,
            'funnel' => collect($keys)->map(fn ($k) => ['key' => $k, 'title' => self::STEPS[$track][$k]['title'], 'users' => $funnel[$k]])->values()->all(),
            'stuck' => $stuck,
        ];
    }
}
