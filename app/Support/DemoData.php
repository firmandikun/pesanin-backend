<?php

namespace App\Support;

use App\Models\AdSpend;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Small, readable demo dataset for the demo seller: a dozen web-checkout orders from different
 * traffic sources, two manual orders, and a week of Meta/TikTok ad spend.
 * Real connected marketplace shops and their orders are never touched.
 */
class DemoData
{
    /** [traffic utm_source, campaign, product key, qty, payment, pay status, status, shipping, courier, service, days ago, buyer, city, district, province] */
    private const WEB = [
        ['facebook', 'Toner-Conversion-Nov', 'toner', 2, 'bank_transfer', 'unpaid', 'pending', 'not_shipped', 'JNE', 'REG', 0, 'Rina Kartika', 'BANDUNG', 'COBLONG', 'JAWA BARAT'],
        ['facebook', 'Toner-Conversion-Nov', 'toner', 1, 'bank_transfer', 'paid', 'processing', 'not_shipped', 'SiCepat', 'REG', 1, 'Dimas Pratama', 'JAKARTA SELATAN', 'TEBET', 'DKI JAKARTA'],
        ['facebook', 'Serum-Retarget-Nov', 'serum', 1, 'cod', 'unpaid', 'processing', 'in_transit', 'J&T', 'EZ', 3, 'Sari Wulandari', 'SURABAYA', 'GUBENG', 'JAWA TIMUR'],
        ['instagram', 'Serum-Reels-Nov', 'serum', 2, 'bank_transfer', 'paid', 'completed', 'delivered', 'SAP', 'REG', 6, 'Putri Ayu', 'YOGYAKARTA', 'GONDOKUSUMAN', 'DI YOGYAKARTA'],
        ['tiktok', 'Toner-Spark-Ads', 'toner', 3, 'epayment', 'paid', 'processing', 'not_shipped', 'SPX Express', 'Standard', 1, 'Bayu Saputra', 'MEDAN', 'MEDAN BARU', 'SUMATERA UTARA'],
        ['tiktok', 'Toner-Spark-Ads', 'toner', 1, 'cod', 'paid', 'completed', 'delivered', 'iDexpress', 'STD', 8, 'Nadia Lestari', 'SEMARANG', 'TEMBALANG', 'JAWA TENGAH'],
        ['google', 'Search-Brand', 'serum', 1, 'bank_transfer', 'paid', 'completed', 'delivered', 'JNE', 'YES', 5, 'Hendra Wijaya', 'TANGERANG SELATAN', 'SERPONG', 'BANTEN'],
        ['whatsapp', 'Broadcast-Promo', 'toner', 2, 'cod', 'unpaid', 'processing', 'picked_up', 'SiCepat', 'BEST', 2, 'Intan Permata', 'BEKASI', 'BEKASI BARAT', 'JAWA BARAT'],
        [null, null, 'toner', 1, 'bank_transfer', 'paid', 'completed', 'delivered', 'Lion Parcel', 'REGPACK', 9, 'Agus Salim', 'MAKASSAR', 'PANAKKUKANG', 'SULAWESI SELATAN'],
        [null, null, 'serum', 1, 'bank_transfer', 'unpaid', 'cancelled', 'not_shipped', 'JNE', 'REG', 4, 'Yoga Prasetyo', 'MALANG', 'KLOJEN', 'JAWA TIMUR'],
        ['facebook', 'Ebook-Leads', 'ebook', 1, 'epayment', 'paid', 'completed', 'not_shipped', null, null, 2, 'Lestari Dewi', null, null, null],
        [null, null, 'ebook', 1, 'bank_transfer', 'paid', 'completed', 'not_shipped', null, null, 7, 'Fajar Nugroho', null, null, null],
    ];

    private const MANUAL = [
        ['toner', 2, 'cod', 'unpaid', 'processing', 'not_shipped', 'J&T', 'EZ', 1, 'Bu Wati (pesan via DM)', 'DEPOK', 'BEJI', 'JAWA BARAT'],
        ['serum', 1, 'bank_transfer', 'paid', 'completed', 'delivered', 'SAP', 'REG', 5, 'Mas Andi (reseller)', 'BOGOR', 'BOGOR TENGAH', 'JAWA BARAT'],
    ];

    public static function reset(User $user): array
    {
        \App\Support\Notifier::$muted = true;
        return DB::transaction(function () use ($user) {
            $realShops = MarketplaceShop::where('user_id', $user->id)->where('is_demo', false)->pluck('id');

            // Drop previous demo data only.
            Order::where('user_id', $user->id)->where(fn ($q) => $q->whereNull('marketplace_shop_id')->orWhereNotIn('marketplace_shop_id', $realShops))->delete();
            AdSpend::where('user_id', $user->id)->where('synced', false)->delete();
            // Marketplace data only comes from real (sandbox or live) Shopee shops; drop any old sample shop.
            MarketplaceShop::where('user_id', $user->id)->where('is_demo', true)->each(fn ($s) => $s->delete());

            $products = Product::where('user_id', $user->id)->get();
            $p = [
                'toner' => $products->first(fn ($x) => str_contains(strtolower($x->name), 'toner')),
                'serum' => $products->first(fn ($x) => str_contains(strtolower($x->name), 'serum')),
                'ebook' => $products->first(fn ($x) => $x->type === 'digital'),
            ];
            // Realistic cost prices so the profit report has something to subtract.
            foreach (['toner' => 15000, 'serum' => 42000, 'ebook' => 2500] as $k => $cost) {
                if ($p[$k] && ! $p[$k]->cost_price) {
                    $p[$k]->update(['cost_price' => $cost]);
                }
            }

            $n = 0;
            foreach (self::WEB as $i => [$utm, $campaign, $key, $qty, $pay, $paid, $status, $ship, $courier, $service, $ago, $buyer, $city, $district, $province]) {
                self::order($user, $p[$key], $i, compact('qty', 'pay', 'paid', 'status', 'ship', 'courier', 'service', 'ago', 'buyer', 'city', 'district', 'province') + [
                    'channel' => 'website', 'source' => 'checkout', 'utm_source' => $utm, 'utm_campaign' => $campaign,
                    'utm_medium' => $utm ? ($utm === 'whatsapp' ? 'broadcast' : 'paid') : null,
                ]);
                $n++;
            }
            foreach (self::MANUAL as $i => [$key, $qty, $pay, $paid, $status, $ship, $courier, $service, $ago, $buyer, $city, $district, $province]) {
                self::order($user, $p[$key], 100 + $i, compact('qty', 'pay', 'paid', 'status', 'ship', 'courier', 'service', 'ago', 'buyer', 'city', 'district', 'province') + [
                    'channel' => 'manual', 'source' => 'manual', 'utm_source' => 'manual', 'utm_campaign' => null, 'utm_medium' => null,
                ]);
                $n++;
            }

            // One week of ad spend for the web-checkout traffic.
            for ($d = 0; $d < 7; $d++) {
                AdSpend::create(['user_id' => $user->id, 'platform' => 'meta', 'spent_on' => now()->subDays($d)->toDateString(), 'amount' => [65000, 80000, 72000, 90000, 58000, 70000, 85000][$d], 'campaign' => 'Toner-Conversion-Nov']);
            }
            for ($d = 0; $d < 3; $d++) {
                AdSpend::create(['user_id' => $user->id, 'platform' => 'tiktok', 'spent_on' => now()->subDays($d)->toDateString(), 'amount' => [40000, 35000, 45000][$d], 'campaign' => 'Toner-Spark-Ads']);
            }

            return ['web_and_manual_orders' => $n, 'ad_spend_days' => 10];
        });
    }

    private static function order(User $user, ?Product $product, int $i, array $o): void
    {
        if (! $product) {
            return;
        }
        $digital = $product->type === 'digital';
        $price = (int) ($product->sale_price ?: $product->price);
        $subtotal = $price * $o['qty'];
        $shipping = $digital ? 0 : [9000, 11000, 12000, 15000, 18000][$i % 5];
        $codFee = $o['pay'] === 'cod' ? (int) round(($subtotal + $shipping) * 0.03) : 0;
        $created = Carbon::now()->subDays($o['ago'])->setTime(8 + ($i * 3) % 13, ($i * 17) % 60);
        $shipped = in_array($o['ship'], ['picked_up', 'in_transit', 'delivered'], true);

        $order = new Order([
            'product_id' => $product->id, 'customer_name' => $o['buyer'], 'phone' => '0812'.str_pad((string) (3000000 + $i * 7919), 8, '0', STR_PAD_LEFT),
            'address' => $digital ? null : 'Jl. Melati No. '.(10 + $i).', RT 02/RW 05', 'province' => $o['province'], 'city' => $o['city'], 'district' => $o['district'],
            'quantity' => $o['qty'], 'unit_price' => $price, 'subtotal' => $subtotal, 'shipping_cost' => $shipping, 'cod_fee' => $codFee,
            'total' => $subtotal + $shipping + $codFee, 'weight' => $product->weight * $o['qty'],
            'payment_method' => $o['pay'], 'payment_status' => $o['paid'], 'status' => $o['status'], 'shipping_status' => $o['ship'],
            'courier' => $o['courier'], 'courier_service' => $o['service'],
            'tracking_number' => $shipped ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $o['courier']), 0, 3)).'26'.str_pad((string) (110000 + $i * 731), 8, '0', STR_PAD_LEFT) : null,
            'source' => $o['source'], 'channel' => $o['channel'], 'utm_source' => $o['utm_source'], 'utm_medium' => $o['utm_medium'], 'utm_campaign' => $o['utm_campaign'],
            'paid_at' => $o['paid'] === 'paid' ? $created->copy()->addMinutes(20) : null,
            'shipped_at' => $shipped ? $created->copy()->addDay() : null,
            'completed_at' => $o['status'] === 'completed' ? $created->copy()->addDays(3) : null,
        ]);
        $order->user_id = $user->id;
        $order->created_at = $created;
        $order->save();
    }
}
