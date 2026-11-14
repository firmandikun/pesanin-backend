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
                'recent' => [$sync->recent($shop), \App\Support\Shopee\ShopeeChatWatch::check($shop)],
                default => $sync->orders($shop),
            };
        } catch (\Throwable $e) {
            $shop->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
            $this->warn("{$shop->shop_name}: {$e->getMessage()}");
        }
    }
    $this->info("Toko disinkronkan: {$shops->count()}");
})->purpose('Sync connected Shopee shops');

// Near real time: orders changed in the last 5 minutes, every 30s (needs `php artisan schedule:work`).
\Illuminate\Support\Facades\Schedule::command('marketplace:sync --what=recent')->everyThirtySeconds()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('marketplace:sync --what=orders')->hourly()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('marketplace:sync --what=products')->dailyAt('02:00');
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

// Orders: automatic resi once paid (Biteship) and automatic delivery of digital products.
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

Artisan::command('awb:send {user}', function (int $user) {
    $u = \App\Models\User::findOrFail($user);
    try {
        $r = \App\Support\AwbSchedule::send($u, 'Kirim manual');
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
