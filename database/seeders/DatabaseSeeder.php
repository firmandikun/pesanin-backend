<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Region;
use App\Models\User;
use App\Support\CheckoutService;
use App\Support\DefaultTemplates;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! Region::exists()) {
            foreach (require database_path('data/regions.php') as $province => [$island, $cities]) {
                foreach ($cities as $city => $districts) {
                    foreach ($districts as $district) {
                        Region::create(compact('province', 'city', 'district', 'island'));
                    }
                }
            }
        }

        $user = User::updateOrCreate(['email' => 'demo@pesanin.test'], [
            'name' => 'Demo Seller',
            'store_name' => 'Toko Demo',
            'store_slug' => 'toko-demo',
            'whatsapp' => '081234567890',
            'bank_accounts' => [
                ['bank' => 'BCA', 'number' => '1234567890', 'holder' => 'Demo Seller'],
                ['bank' => 'Mandiri', 'number' => '0987654321', 'holder' => 'Demo Seller'],
            ],
            'password' => 'password',
        ]);

        // Demo store runs on an active Bisnis plan with one paid invoice.
        $user->forceFill(['plan' => 'bisnis', 'plan_billing' => 'monthly', 'plan_expires_at' => now()->addDays(20)->endOfDay(), 'plan_auto_renew' => true])->save();
        $user->subscriptionInvoices()->firstOrCreate(['number' => 'INV/PSN/DEMO/0001'], [
            'plan' => 'bisnis', 'billing' => 'monthly', 'amount' => 129000, 'tax' => 14190, 'total' => 143190,
            'method' => 'va_bca', 'pay_code' => '393580000011234', 'status' => 'paid',
            'period_start' => now()->subDays(10)->toDateString(), 'period_end' => now()->addDays(20)->toDateString(),
            'due_at' => now()->subDays(9), 'paid_at' => now()->subDays(10),
        ]);

        // One login per plan for testing quotas and feature gates (password: "password").
        foreach (['gratis' => null, 'starter' => 'monthly', 'pro' => 'yearly'] as $plan => $billing) {
            $seller = User::updateOrCreate(['email' => "{$plan}@pesanin.test"], [
                'name' => 'Seller '.ucfirst($plan), 'store_name' => 'Toko '.ucfirst($plan), 'store_slug' => "toko-{$plan}",
                'whatsapp' => '081200000000', 'password' => 'password',
            ]);
            $seller->forceFill([
                'plan' => $plan, 'plan_billing' => $billing, 'plan_auto_renew' => true,
                'plan_expires_at' => $billing ? now()->add($billing === 'yearly' ? '1 year' : '1 month')->endOfDay() : null,
            ])->save();
            if (! $seller->followupTemplates()->exists()) {
                DefaultTemplates::seed($seller);
            }
        }

        if (! $user->followupTemplates()->exists()) {
            DefaultTemplates::seed($user);
        }
        $user->addresses()->firstOrCreate(['label' => 'Gudang Utama'], [
            'name' => 'Demo Seller', 'phone' => '081234567890', 'address' => 'Jl. Raya Serpong No. 10',
            'region_id' => Region::where(['city' => 'TANGERANG SELATAN', 'district' => 'SERPONG'])->value('id'),
            'city' => 'TANGERANG SELATAN', 'postal_code' => '15310', 'is_default' => true,
        ]);

        $toner = $user->products()->updateOrCreate(['slug' => 'toner-lemon'], [
            'name' => 'Toner Lemon 60ml', 'category' => 'Kecantikan', 'price' => 129900, 'sale_price' => 49900,
            'cost_price' => 15000, 'sku' => 'TNR001', 'track_stock' => true, 'stock' => 500, 'weight' => 100,
            'description' => 'Toner wajah dengan ekstrak lemon untuk kulit cerah dan segar.',
            'features' => ['BPOM & Halal', 'Cocok untuk semua jenis kulit', 'Hasil terlihat dalam 7 hari'],
            'wholesale_prices' => [['min_qty' => 3, 'price' => 45000]],
            'payment_methods' => ['bank_transfer', 'cod'],
            'form_settings' => ['email' => 'hidden', 'notes' => true, 'header_title' => 'Promo Spesial Hari Ini!'],
            'success_settings' => ['action' => 'page'],
        ]);
        $serum = $user->products()->updateOrCreate(['slug' => 'eyelash-serum'], [
            'name' => 'Eyelash Serum 10ml', 'category' => 'Kecantikan', 'price' => 159900, 'sale_price' => 129900,
            'weight' => 50, 'description' => 'Serum penumbuh bulu mata dalam 14 hari.',
            'features' => ['Hasil terlihat 14 hari', 'Aman untuk mata sensitif'],
            'variant_options' => [['type' => 'Paket', 'values' => ['Single', 'Paket Duo']]],
            'variants' => [['name' => 'Single', 'price' => 129900], ['name' => 'Paket Duo', 'price' => 239900]],
            'payment_methods' => ['bank_transfer', 'cod', 'epayment'],
        ]);
        $ebook = $user->products()->updateOrCreate(['slug' => 'ebook-jualan-online'], [
            'name' => 'E-book Jago Jualan Online', 'type' => 'digital', 'category' => 'Buku', 'price' => 99000, 'sale_price' => 49000,
            'description' => 'Panduan lengkap jualan online untuk pemula.', 'payment_methods' => ['bank_transfer', 'epayment'],
        ]);

        $toner->update(['bump' => ['enabled' => true, 'items' => [['product_id' => $serum->id, 'price' => 99000, 'description' => 'Lengkapi perawatanmu, hemat Rp30.900']]]]);
        [$toner, $serum, $ebook] = [$toner->refresh(), $serum->refresh(), $ebook->refresh()];
        if (! $user->landingPages()->exists()) {
            $user->landingPages()->create([
                'title' => 'Toner Lemon — Kulit Cerah 7 Hari', 'slug' => 'toner-lemon-promo', 'label' => 'Promo',
                'status' => 'published', 'template' => 'product_solution', 'product_id' => $toner->id,
                'theme' => ['primary' => '#16a34a', 'background' => '#fffdf5', 'width' => 'narrow'],
                'seo' => ['title' => 'Toner Lemon — Kulit Cerah 7 Hari', 'description' => 'Promo spesial toner lemon.'],
                'settings' => ['floating_whatsapp' => true],
                'blocks' => \App\Support\LandingTemplates::blocks('product_solution', $toner),
            ]);
        }
        if (Order::where('user_id', $user->id)->exists()) {
            return;
        }
        $regionIds = Region::pluck('id')->all();
        $names = ['Siti Aminah', 'Budi Santoso', 'Dewi Lestari', 'Andi Pratama', 'Rina Wulandari', 'Agus Setiawan', 'Nurul Hidayah', 'Fajar Nugroho'];
        foreach (range(1, 70) as $i) {
            $product = [$toner, $toner, $serum, $ebook][$i % 4];
            $method = CheckoutService::paymentMethods($product)[array_rand(CheckoutService::paymentMethods($product))];
            $data = [
                'customer_name' => $names[array_rand($names)],
                'phone' => '0812'.random_int(10000000, 99999999),
                'email' => $product->type === 'digital' ? 'pembeli'.$i.'@example.com' : null,
                'address' => $product->type === 'physical' ? 'Jl. Contoh No. '.random_int(1, 200) : null,
                'region_id' => $product->type === 'physical' ? $regionIds[array_rand($regionIds)] : null,
                'variant' => $product->variants ? $product->variants[array_rand($product->variants)]['name'] : null,
                'quantity' => random_int(1, 4),
                'bump_items' => $product->id === $toner->id && random_int(0, 1) ? [$serum->id] : [],
                'payment_method' => $method,
                'utm_source' => ['facebook', 'instagram', 'tiktok', 'google', null][random_int(0, 4)],
            ];
            $order = CheckoutService::place($product, $data);
            $at = now()->subDays(random_int(0, 29))->subMinutes(random_int(0, 1400));

            if ($i > 62) {
                $order->forceFill(['is_abandoned' => true, 'created_at' => $at])->save();
                continue;
            }
            $roll = random_int(1, 10);
            $paid = $method === 'cod' ? $roll >= 6 : $roll >= 4;
            $shipped = $product->type === 'physical' && ($paid || $method === 'cod') && $roll >= 5;
            $order->forceFill([
                'created_at' => $at,
                'payment_status' => $paid ? 'paid' : 'unpaid',
                'paid_at' => $paid ? $at->copy()->addHours(3) : null,
                'status' => match (true) {
                    $roll === 1 => 'cancelled',
                    $roll >= 8 && $paid => 'completed',
                    $paid || $shipped => 'processing',
                    default => 'pending',
                },
                'shipping_status' => $shipped ? ($roll >= 8 ? 'delivered' : 'picked_up') : 'not_shipped',
                'tracking_number' => $shipped ? 'JNE'.random_int(1000000000, 9999999999) : null,
                'shipped_at' => $shipped ? $at->copy()->addDay() : null,
            ])->save();
        }
    }
}
