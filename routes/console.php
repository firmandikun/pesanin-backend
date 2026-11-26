<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-renew: issue the next invoice 3 days before a paid plan expires.
Artisan::command('billing:renew', function () {
    $users = \App\Models\User::where('plan', '!=', 'gratis')->where('plan_auto_renew', true)
        ->whereBetween('plan_expires_at', [now(), now()->addDays(3)])
        ->whereDoesntHave('subscriptionInvoices', fn ($q) => $q->where('status', 'unpaid'))
        ->get();
    foreach ($users as $user) {
        $last = $user->subscriptionInvoices()->where('status', 'paid')->latest('id')->first();
        \App\Support\Subscriptions::createInvoice($user, $user->plan, $user->plan_billing ?? 'monthly', $last->method ?? 'va_bca');
    }
    $this->info("Tagihan perpanjangan dibuat: {$users->count()}");
})->purpose('Issue renewal invoices for plans expiring within 3 days');

\Illuminate\Support\Facades\Schedule::command('billing:renew')->dailyAt('07:00');

// Shopee: pull new orders often (push covers real-time when configured), listings and ad spend daily.
Artisan::command('marketplace:sync {--what=orders}', function () {
    $sync = new \App\Support\Shopee\ShopeeSync;
    $shops = \App\Models\MarketplaceShop::where('status', 'active')->where('is_demo', false)->get();
    foreach ($shops as $shop) {
        try {
            match ($this->option('what')) {
                'products' => $sync->products($shop),
                'ads' => $sync->ads($shop),
                'stats' => (new \App\Support\Shopee\ShopeeProductTools)->syncStats($shop),
                'recent' => [$sync->recent($shop), \App\Support\Shopee\ShopeeChatWatch::check($shop)],
                default => $sync->orders($shop),
            };
        } catch (\Throwable $e) {
            $shop->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
            $this->warn("{$shop->shop_name}: {$e->getMessage()}");
        }
    }
    // Penjaga Iklan: right after fresh ad numbers, alert (and optionally act) on ads below break-even.
    if ($this->option('what') === 'ads') {
        foreach ($shops->pluck('user_id')->unique() as $userId) {
            try {
                \App\Support\AdsGuard::check(\App\Models\User::findOrFail($userId));
            } catch (\Throwable $e) {
                $this->warn("Penjaga iklan user {$userId}: {$e->getMessage()}");
            }
        }
    }
    $this->info("Toko disinkronkan: {$shops->count()}");
})->purpose('Sync connected Shopee shops');

// Near real time: orders changed in the last 5 minutes, every 30s (needs `php artisan schedule:work`).
\Illuminate\Support\Facades\Schedule::command('marketplace:sync --what=recent')->everyThirtySeconds()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('marketplace:sync --what=orders')->hourly()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('marketplace:sync --what=products')->dailyAt('02:00');
// Statistik produk (dilihat / disukai / terjual) snapshot, twice a day for the 7-day change.
\Illuminate\Support\Facades\Schedule::command('marketplace:sync --what=stats')->twiceDaily(3, 15)->withoutOverlapping();
// Naikkan Produk otomatis: refill free boost slots (5 per shop, 4 hours each).
// Scan Out: keep the handover history 10 days.
Artisan::command('scan-out:prune', function () {
    $this->info('Sesi scan out dihapus: '.\App\Support\ScanOut::prune());
})->purpose('Delete Scan Out sessions older than 10 days');
\Illuminate\Support\Facades\Schedule::command('scan-out:prune')->dailyAt('01:30');
Artisan::command('marketplace:boost', function () {
    $this->info('Toko diproses: '.\App\Support\Shopee\ShopeeProductTools::runAllBoosts());
})->purpose('Boost Shopee products from each shop rotation list');
\Illuminate\Support\Facades\Schedule::command('marketplace:boost')->everyTenMinutes()->withoutOverlapping();
// Ad spend for today keeps changing, so refresh it through the day.
\Illuminate\Support\Facades\Schedule::command('marketplace:sync --what=ads')->everyThreeHours()->withoutOverlapping();

// Replace the demo seller's sample orders/ads with a small, readable dataset (real Shopee shops are kept).
Artisan::command('webpush:vapid', function () {
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    $env = base_path('.env');
    $text = file_get_contents($env);
    foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $k => $v) {
        $text = preg_match("/^{$k}=.*$/m", $text) ? preg_replace("/^{$k}=.*$/m", "{$k}={$v}", $text) : rtrim($text)."\n{$k}={$v}\n";
    }
    file_put_contents($env, $text);
    $this->info('Kunci VAPID dibuat dan disimpan ke .env');
})->purpose('Generate Web Push (VAPID) keys');

Artisan::command('demo:reset {email=demo@pesanin.test}', function (string $email) {
    $user = \App\Models\User::where('email', $email)->firstOrFail();
    $this->info(json_encode(\App\Support\DemoData::reset($user)));
})->purpose('Reset demo data to a compact sample set');

// Meta Ads: yesterday's numbers settle overnight, so re-pull the last 3 days every morning (and every 3 hours for today).
Artisan::command('meta-ads:sync {--days=3}', function () {
    foreach (\App\Models\UserIntegration::where('provider', 'meta_ads')->where('status', 'active')->get() as $i) {
        try {
            \App\Support\Meta\MetaAdsSync::run($i, (int) $this->option('days'));
        } catch (\Throwable $e) {
            $i->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
            $this->warn("user {$i->user_id}: {$e->getMessage()}");
        }
    }
})->purpose('Sync Meta ads insights');
\Illuminate\Support\Facades\Schedule::command('meta-ads:sync --days=3')->everyThreeHours()->withoutOverlapping();

// Messenger + Instagram Direct: poll connected Pages for new buyer messages (works without a public webhook).
Artisan::command('meta-chat:watch', function () {
    foreach (\App\Models\UserIntegration::where('provider', 'meta_chat')->where('status', 'active')->with('user')->get() as $i) {
        \App\Support\Meta\MetaChatWatch::check($i);
    }
})->purpose('Notify new Meta chat messages');
\Illuminate\Support\Facades\Schedule::command('meta-chat:watch')->everyThirtySeconds()->withoutOverlapping();

// Orders: Shopee auto-arrange, automatic delivery of digital products and stock master pushes.
Artisan::command('orders:automate', function () {
    $r = \App\Support\OrderAutomation::run();
    if ($r['shipped'] || $r['delivered']) {
        $this->info("orders: {$r['shipped']} resi, {$r['delivered']} produk digital");
    }
})->purpose('Auto resi + digital product delivery');
\Illuminate\Support\Facades\Schedule::command('orders:automate')->everyThirtySeconds()->withoutOverlapping();

// Scheduled "cetak resi": at each seller's chosen times, send the unprinted Shopee AWBs to their Telegram.
Artisan::command('awb:schedule', function () {
    if ($n = \App\Support\AwbSchedule::run()) {
        $this->info("awb: {$n} resi dikirim ke Telegram");
    }
})->purpose('Send scheduled Shopee AWB PDFs to Telegram');
\Illuminate\Support\Facades\Schedule::command('awb:schedule')->everyMinute()->withoutOverlapping(30);

Artisan::command('awb:send {user} {--all : Every ready label, not only the unprinted ones}', function (int $user) {
    $u = \App\Models\User::findOrFail($user);
    try {
        $r = \App\Support\AwbSchedule::send($u, 'Kirim manual', $this->option('all') ? 'all' : 'new');
        $this->info("awb: {$r['count']} resi dikirim");
    } catch (\Throwable $e) {
        \App\Support\Notifier::send($u->id, 'order', 'Kirim resi ke Telegram gagal', mb_substr($e->getMessage(), 0, 200), '/settings/automation');
        $this->error($e->getMessage());
    }
})->purpose('Send the unprinted Shopee AWBs to Telegram now');

// WhatsApp broadcast: send due broadcasts a small batch at a time (stays under Meta's tier).
Artisan::command('broadcast:send', function () {
    $n = \App\Support\WhatsApp\BroadcastSender::run();
    if ($n) {
        $this->info("broadcast: {$n} sent");
    }
})->purpose('Send queued WhatsApp broadcasts');
\Illuminate\Support\Facades\Schedule::command('broadcast:send')->everyFifteenSeconds()->withoutOverlapping();

// php artisan pesanin:admin email@domain.com  (--revoke to remove)
Artisan::command('pesanin:admin {email} {--revoke}', function (string $email) {
    $user = \App\Models\User::where('email', $email)->first();
    if (! $user) {
        $this->error("User {$email} tidak ditemukan.");

        return 1;
    }
    $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
    $this->info($user->is_admin ? "{$email} sekarang admin Pesanin." : "Akses admin {$email} dicabut.");
})->purpose('Grant or revoke Pesanin platform admin access');

// Shopee ISV application: a ready-to-review seller account for Shopee's reviewers (Pro plan, sample Shopee shop).
Artisan::command('pesanin:reviewer {email=shopee.review@pesaninn.my.id} {--reset : Set a new password}', function (string $email) {
    $user = \App\Models\User::where('email', $email)->first();
    $password = null;
    if (! $user || $this->option('reset')) {
        $password = \Illuminate\Support\Str::password(16, symbols: false);
    }
    if (! $user) {
        $user = \App\Models\User::create([
            'name' => 'Shopee Reviewer', 'store_name' => 'Toko Uji Shopee', 'store_slug' => 'toko-uji-shopee-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
            'email' => $email, 'password' => $password,
        ]);
        \App\Support\DefaultTemplates::seed($user);
    } elseif ($password) {
        $user->forceFill(['password' => $password])->save();
    }
    // Every feature open for a year, no trial lock.
    $user->forceFill(['plan' => 'pro', 'plan_billing' => 'yearly', 'plan_expires_at' => now()->addYear()->endOfDay(), 'plan_auto_renew' => false, 'suspended_at' => null, 'is_admin' => false])->save();
    // Sample products (slugs are unique across Pesanin, so they carry the user id) for the catalogue, checkout and Shopee linking.
    if (! $user->products()->exists()) {
        $sid = $user->id;
        $user->products()->create([
            'name' => 'Toner Lemon 60ml', 'slug' => "toner-lemon-{$sid}", 'category' => 'Kecantikan', 'price' => 129900, 'sale_price' => 49900,
            'cost_price' => 15000, 'sku' => 'TNR001', 'track_stock' => true, 'stock' => 500, 'weight' => 100,
            'description' => 'Toner wajah dengan ekstrak lemon untuk kulit cerah dan segar.', 'payment_methods' => ['bank_transfer', 'cod'],
        ]);
        $user->products()->create([
            'name' => 'Eyelash Serum 10ml', 'slug' => "eyelash-serum-{$sid}", 'category' => 'Kecantikan', 'price' => 159900, 'sale_price' => 129900,
            'cost_price' => 40000, 'sku' => 'SRM010', 'track_stock' => true, 'stock' => 200, 'weight' => 50,
            'description' => 'Serum penumbuh bulu mata dalam 14 hari.', 'payment_methods' => ['bank_transfer', 'cod', 'epayment'],
        ]);
        $user->products()->create([
            'name' => 'Masker Wajah Organik', 'slug' => "masker-organik-{$sid}", 'category' => 'Kecantikan', 'price' => 89000, 'sale_price' => 69000,
            'cost_price' => 22000, 'sku' => 'MSK020', 'track_stock' => true, 'stock' => 300, 'weight' => 120,
            'description' => 'Masker wajah dari bahan organik untuk kulit lembap.', 'payment_methods' => ['bank_transfer', 'cod'],
        ]);
    }
    if (! $user->marketplaceShops()->where('is_demo', true)->exists()) {
        \App\Support\Shopee\ShopeeDemo::seed($user);
    }
    $this->info('Akun uji coba untuk reviewer Shopee siap:');
    $this->line('  URL      : '.rtrim(config('services.frontend_url') ?: config('app.url'), '/').'/login');
    $this->line('  Email    : '.$email);
    $this->line('  Password : '.($password ?? '(tidak diubah; pakai --reset untuk membuat password baru)'));
    $this->line('  Paket    : Pro, aktif s/d '.$user->plan_expires_at->toDateString().' (semua fitur terbuka)');
})->purpose('Create or refresh the trial account given to Shopee for the ISV review');

// First full sync of a newly connected Shopee shop, started in the background by the OAuth callback.
Artisan::command('marketplace:initial-sync {shop}', function (int $shop) {
    $s = \App\Models\MarketplaceShop::find($shop);
    if (! $s || $s->is_demo) {
        return;
    }
    $this->info(now()->toDateTimeString()." sinkron awal toko {$s->id} ({$s->shop_name}) dimulai");
    (new \App\Support\Shopee\ShopeeSync)->initial($s);
    $this->info(now()->toDateTimeString()." sinkron awal toko {$s->id} selesai");
})->purpose('Initial sync (profile, products, orders, ads) of a newly connected Shopee shop');

// Pull older Shopee orders for a date range a report asked for (started in the background by ShopeeBackfill).
Artisan::command('marketplace:backfill {shop} {from}', function (int $shop, string $from) {
    $s = \App\Models\MarketplaceShop::find($shop);
    if (! $s || $s->is_demo) {
        return;
    }
    try {
        $n = (new \App\Support\Shopee\ShopeeSync)->backfill($s, \Illuminate\Support\Carbon::parse($from));
        $this->info(now()->toDateTimeString()." backfill toko {$s->id} sejak {$from}: {$n} pesanan");
    } finally {
        \Illuminate\Support\Facades\Cache::forget("shopee:backfill:{$s->id}");
    }
})->purpose('Backfill older Shopee orders for a report date range');

// Ulasan Shopee: sync hourly, auto-reply good reviews (if enabled), alert on low ratings.
Artisan::command('shopee:reviews', function () {
    $users = \App\Models\User::whereHas('marketplaceShops', fn ($q) => $q->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false))->get();
    $n = 0;
    foreach ($users as $user) {
        try {
            $n += \App\Support\Shopee\ShopeeReviews::run($user);
        } catch (\Throwable $e) {
            $this->warn("Ulasan user {$user->id}: {$e->getMessage()}");
        }
    }
    $this->info("Ulasan baru: {$n}");
})->purpose('Sync Shopee reviews, auto-reply, alert low ratings');
\Illuminate\Support\Facades\Schedule::command('shopee:reviews')->hourly()->withoutOverlapping();

// Chat Shopee otomatis: hanya MEMBALAS chat pembeli (kata kunci & pesan di luar jam kerja), tidak pernah chat duluan.
Artisan::command('shopee:chat-auto', function () {
    $users = \App\Models\User::whereHas('marketplaceShops', fn ($q) => $q->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false))
        ->get()->filter(fn ($u) => ! empty($u->settings[\App\Support\Shopee\ChatAutomation::KEY]));
    $t = 0;
    foreach ($users as $user) {
        try {
            $t += \App\Support\Shopee\ChatAutomation::run($user)['replies'];
        } catch (\Throwable $e) {
            $this->warn("Chat otomatis user {$user->id}: {$e->getMessage()}");
        }
    }
    $this->info("Chat otomatis: {$t} balasan");
})->purpose('Shopee chat automation: auto-replies to buyer messages');
\Illuminate\Support\Facades\Schedule::command('shopee:chat-auto')->everyMinute()->withoutOverlapping(10);

// Cek Margin: every morning, tell sellers about products that newly sell at a loss or below their minimum margin.
Artisan::command('shopee:margins', function () {
    $n = 0;
    foreach (\App\Models\User::whereHas('marketplaceShops', fn ($q) => $q->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false))->get() as $user) {
        try {
            $n += \App\Support\MarginCheck::notify($user);
        } catch (\Throwable $e) {
            $this->warn("Margin user {$user->id}: {$e->getMessage()}");
        }
    }
    $this->info("Produk margin tipis/rugi baru: {$n}");
})->purpose('Alert sellers about Shopee products with thin or negative margin');
\Illuminate\Support\Facades\Schedule::command('shopee:margins')->dailyAt('09:00')->timezone('Asia/Jakarta')->withoutOverlapping();

// Cek Dana: every morning, tell sellers about money to check / claim (shipping charged, lost parcels, returns…).
Artisan::command('shopee:money-check', function () {
    $n = 0;
    foreach (\App\Models\User::whereHas('marketplaceShops', fn ($q) => $q->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false))->get() as $user) {
        try {
            $n += \App\Support\MoneyCheck::notify($user);
        } catch (\Throwable $e) {
            $this->warn("Cek dana user {$user->id}: {$e->getMessage()}");
        }
    }
    $this->info("Temuan dana baru: {$n}");
})->purpose('Alert sellers about Shopee money to check or claim');
\Illuminate\Support\Facades\Schedule::command('shopee:money-check')->dailyAt('08:30')->timezone('Asia/Jakarta')->withoutOverlapping();

// Batas kirim: every 30 minutes, warn before orders become late (penalty points) and when they are late.
Artisan::command('shopee:ship-deadline', function () {
    $n = 0;
    foreach (\App\Models\User::whereHas('marketplaceShops', fn ($q) => $q->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false))->get() as $user) {
        try {
            $n += \App\Support\Shopee\ShipDeadline::notify($user);
        } catch (\Throwable $e) {
            $this->warn("Batas kirim user {$user->id}: {$e->getMessage()}");
        }
    }
    $this->info("Peringatan batas kirim: {$n}");
})->purpose('Warn sellers before Shopee orders pass the ship-by deadline');
\Illuminate\Support\Facades\Schedule::command('shopee:ship-deadline')->everyThirtyMinutes()->withoutOverlapping();

// Prediksi stok: every morning, list SKUs that ran out or will run out before new stock can arrive.
Artisan::command('stock:restock-alert', function () {
    $n = 0;
    foreach (\App\Models\User::whereIn('id', \App\Models\StockMaster::distinct()->pluck('user_id'))->get() as $user) {
        try {
            $n += \App\Support\RestockForecast::notify($user);
        } catch (\Throwable $e) {
            $this->warn("Restock user {$user->id}: {$e->getMessage()}");
        }
    }
    $this->info("SKU perlu restock (baru): {$n}");
})->purpose('Alert sellers about SKUs that need restocking');
\Illuminate\Support\Facades\Schedule::command('stock:restock-alert')->dailyAt('07:30')->timezone('Asia/Jakarta')->withoutOverlapping();

// KiriminAja: register this server's webhook URL (status updates, AWB, sorting code) on Pesanin's KiriminAja account.
Artisan::command('kiriminaja:callback {url?}', function (?string $url = null) {
    $api = \App\Support\KiriminAja\KiriminAja::for(\App\Support\KiriminAja\KiriminAja::platform());
    if (! $api) {
        $this->error('KIRIMINAJA_API_KEY belum diisi.');

        return 1;
    }
    $secret = (string) config('services.kiriminaja.webhook_secret');
    $url ??= rtrim(config('app.url'), '/').'/api/kiriminaja/webhook'.($secret !== '' ? '?token='.urlencode($secret) : '');
    $api->setCallback($url);
    $this->info('Callback KiriminAja terdaftar: '.preg_replace('/token=[^&]+/', 'token=***', $url));
})->purpose('Register the KiriminAja webhook callback URL');

// KiriminAja: check one order against KiriminAja (resi, status) and link it if it was booked but not recorded.
Artisan::command('kiriminaja:check {code : Kode pesanan Pesanin, mis. FK2611261ZIMU} {--raw : Tampilkan jawaban mentah tracking KiriminAja}', function (string $code) {
    $order = \App\Models\Order::where('code', $code)->first();
    $api = \App\Support\KiriminAja\KiriminAja::for(\App\Support\KiriminAja\KiriminAja::platform());
    if (! $order || ! $api) {
        $this->error(! $order ? "Pesanan {$code} tidak ditemukan." : 'KIRIMINAJA_API_KEY belum diisi.');

        return 1;
    }
    $prefix = trim((string) config('services.kiriminaja.prefix', ''));
    $kaId = $order->shipping_ref ?: ($prefix !== '' ? "{$prefix}-{$order->code}" : $order->code);
    $this->line("Pesanin: ref=".($order->shipping_ref ?: '-')." provider=".($order->shipping_provider ?: '-')." resi=".($order->tracking_number ?: '-')." status=".$order->shipping_status);
    \Illuminate\Support\Facades\Cache::forget('kiriminaja:track:'.(config('services.kiriminaja.sandbox') ? 'sbx' : 'live').':'.$kaId);
    try {
        $t = $api->track($kaId);
    } catch (\Throwable $e) {
        $this->error("KiriminAja ({$kaId}): ".$e->getMessage());

        return 1;
    }
    if ($this->option('raw')) {
        $this->line(json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $d = $t['details'] ?? [];
    if (! $d) {
        $this->warn("KiriminAja tidak punya paket {$kaId}.");

        return 1;
    }
    $awb = trim((string) ($d['awb'] ?? ''));
    $status = \App\Support\KiriminAja\KiriminAja::trackStatus($t);
    $last = collect($t['histories'] ?? [])->sortByDesc('created_at')->first() ?? [];
    $this->line("KiriminAja: order={$kaId} resi=".($awb ?: '-').' kode status='.($d['status_code'] ?? $last['status_code'] ?? $t['status_code'] ?? '-')
        .(! empty($d['delivered']) ? ' (delivered)' : '').' → '.($status ?: 'belum dikirim'));
    $cf = (array) ($order->custom_fields ?? []);
    $cf['kiriminaja'] = array_merge((array) ($cf['kiriminaja'] ?? []), array_filter(['sorting_code' => $d['sorting_code'] ?? null]));
    $order->forceFill(array_filter([
        'shipping_ref' => $order->shipping_ref ?: $kaId, 'shipping_provider' => 'kiriminaja', 'auto_ship_error' => null,
        'tracking_number' => $order->tracking_number ?: ($awb ?: null), 'shipping_status' => $status, 'custom_fields' => $cf,
        'status' => $status === 'delivered' ? 'completed' : ($order->status === 'pending' ? 'processing' : null),
    ], fn ($v) => $v !== null))->save();
    $this->info('Pesanan diperbarui: resi='.($order->tracking_number ?: '-').' status='.$order->shipping_status);
})->purpose('Sync one order with KiriminAja (resi, status) and link a booking that was not recorded');

// KiriminAja: does this server accept the webhook as registered (URL + token)? Sends a harmless test event.
Artisan::command('kiriminaja:webhook-test', function () {
    $secret = (string) config('services.kiriminaja.webhook_secret');
    $url = rtrim(config('app.url'), '/').'/api/kiriminaja/webhook'.($secret !== '' ? '?token='.urlencode($secret) : '');
    $res = \Illuminate\Support\Facades\Http::timeout(15)->acceptJson()->post($url, ['method' => 'test', 'data' => []]);
    $res->successful()
        ? $this->info("OK ({$res->status()}): webhook diterima. Kalau KiriminAja masih gagal, jalankan php artisan kiriminaja:callback lagi.")
        : $this->error("Gagal ({$res->status()}): ".mb_substr($res->body(), 0, 200).($res->status() === 403 ? ' → token beda: jalankan php artisan config:cache lalu php artisan kiriminaja:callback.' : ''));
})->purpose('Check that this server accepts the KiriminAja webhook (URL + token)');

// KiriminAja: pick up AWBs issued after booking (fallback when the webhook did not arrive).
Artisan::command('kiriminaja:awb', function () {
    $this->info('Resi KiriminAja baru: '.\App\Support\KiriminAja\CourierBooking::fetchAwbs());
})->purpose('Fetch AWBs of booked KiriminAja shipments that have no resi yet');
\Illuminate\Support\Facades\Schedule::command('kiriminaja:awb')->everyTenMinutes()->withoutOverlapping();

// Kesehatan akun Shopee: every morning, alert on new penalty points, late orders or a performance drop.
Artisan::command('shopee:health', function () {
    $users = \App\Models\User::whereHas('marketplaceShops', fn ($q) => $q->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false))->get();
    foreach ($users as $user) {
        try {
            \App\Support\Shopee\ShopeeHealth::check($user);
        } catch (\Throwable $e) {
            $this->warn("Kesehatan akun user {$user->id}: {$e->getMessage()}");
        }
    }
})->purpose('Check Shopee account health and alert');
\Illuminate\Support\Facades\Schedule::command('shopee:health')->dailyAt('08:00')->timezone('Asia/Jakarta');

// Tes konfigurasi SMTP (mis. Sumopod): php artisan mail:test tujuan@email.com
Artisan::command('mail:test {to}', function (string $to) {
    $cfg = config('mail.mailers.smtp');
    $this->info('Mailer: '.config('mail.default').' · host: '.($cfg['host'] ?? '-').':'.($cfg['port'] ?? '-').' · scheme: '.($cfg['scheme'] ?? 'auto').' · from: '.config('mail.from.address'));
    try {
        \Illuminate\Support\Facades\Mail::raw('Ini email tes dari Pesanin. Kalau kamu menerima ini, SMTP sudah berfungsi.',
            fn ($m) => $m->to($to)->subject('Tes SMTP Pesanin'));
        $this->info("Terkirim ke {$to}. Cek inbox/spam.");
    } catch (\Throwable $e) {
        $this->error('Gagal: '.$e->getMessage());

        return 1;
    }
})->purpose('Send a test email to verify SMTP config');
