<?php

namespace App\Support\Shopee;

use App\Models\AdSpend;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;

/**
 * Local-only sample Shopee shop so the marketplace screens can be explored before real
 * Partner credentials exist. Everything it creates is flagged is_demo and removed on disconnect.
 */
class ShopeeDemo
{
    private const CITIES = [
        ['Kota Bandung', 'Jawa Barat', 'Coblong'], ['Kota Surabaya', 'Jawa Timur', 'Gubeng'], ['Kota Medan', 'Sumatera Utara', 'Medan Baru'],
        ['Kota Yogyakarta', 'DI Yogyakarta', 'Gondokusuman'], ['Kota Makassar', 'Sulawesi Selatan', 'Panakkukang'], ['Kota Semarang', 'Jawa Tengah', 'Tembalang'],
    ];

    private const NAMES = ['Dewi', 'Rizky', 'Putri', 'Agus', 'Nadia', 'Fajar', 'Intan', 'Yoga', 'Sinta', 'Bayu', 'Lestari', 'Hendra'];

    public static function seed(User $user): MarketplaceShop
    {
        $shop = MarketplaceShop::create([
            'user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 900000000 + $user->id * 10 + MarketplaceShop::where('user_id', $user->id)->count(),
            'shop_name' => 'Contoh: '.$user->store_name.' Official Shop', 'status' => 'active', 'is_demo' => true,
            'products_synced_at' => now(), 'orders_synced_at' => now(),
        ]);

        // Listings: mirror the seller's own products (linked) plus one item not yet linked.
        $products = $user->products()->where('type', 'physical')->take(3)->get();
        $items = [];
        $itemId = 2200000000 + $shop->id * 100;
        foreach ($products as $p) {
            $price = (int) ($p->sale_price ?: $p->price);
            foreach ([null, 'Paket Hemat isi 2'] as $j => $model) {
                $items[] = MarketplaceListing::create([
                    'user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'product_id' => $j === 0 ? $p->id : null,
                    'item_id' => $itemId, 'model_id' => $j ? $itemId * 10 + 1 : 0, 'name' => $p->name, 'model_name' => $model,
                    'sku' => $p->sku ? $p->sku.($j ? '-2' : '') : null, 'price' => $j ? (int) round($price * 1.85) : $price,
                    'stock' => [42, 6, 0, 120][($itemId + $j * 3) % 4], 'status' => 'NORMAL', 'image' => $p->images[0] ?? null,
                ]);
            }
            $itemId++;
        }
        $items[] = MarketplaceListing::create([
            'user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'item_id' => $itemId, 'model_id' => 0,
            'name' => 'Pouch Kosmetik Travel', 'sku' => 'POUCH-01', 'price' => 35000, 'stock' => 75, 'status' => 'NORMAL',
        ]);

        // 10 orders: 5 waiting to ship (for single & bulk "Atur Pengiriman"), 5 already shipped or finished.
        // [order_status, courier, days ago, listing index, qty]
        $plan = [
            ['READY_TO_SHIP', 'SPX Standard', 0, 0, 1], ['READY_TO_SHIP', 'J&T Express', 0, 2, 2], ['READY_TO_SHIP', 'JNE Reguler', 1, 1, 1],
            ['READY_TO_SHIP', 'SiCepat REG', 1, 4, 3], ['READY_TO_SHIP', 'Anteraja Reguler', 2, 0, 2],
            ['SHIPPED', 'SPX Standard', 3, 2, 1], ['SHIPPED', 'J&T Express', 4, 0, 1], ['TO_CONFIRM_RECEIVE', 'JNE Reguler', 5, 3, 1],
            ['COMPLETED', 'SiCepat REG', 8, 0, 2], ['COMPLETED', 'Anteraja Reguler', 10, 2, 1],
        ];
        $sync = new ShopeeSync;
        foreach ($plan as $i => [$status, $courier, $ago, $li, $qty]) {
            $line = $items[$li % count($items)];
            $created = now()->subDays($ago)->setTime(9 + $i, ($i * 13) % 60);
            [$city, $state, $district] = self::CITIES[$i % count(self::CITIES)];
            $ship = [9000, 11000, 12000, 15000, 18000][$i % 5];
            $sn = $created->format('ymd').strtoupper(substr(md5($shop->id.'-'.$i), 0, 8));
            $order = $sync->upsertOrder($shop, [
                'order_sn' => $sn, 'order_status' => $status, 'create_time' => $created->timestamp,
                'pay_time' => $created->copy()->addMinutes(12)->timestamp,
                'buyer_username' => strtolower(self::NAMES[$i]).($i + 21),
                'recipient_address' => ['name' => self::NAMES[$i].' '.['S.', 'P.', 'W.', 'R.'][$i % 4], 'phone' => '6281******'.($i + 10), 'full_address' => 'Jl. Contoh No. '.($i + 5).", {$district}, {$city}, {$state}", 'city' => $city, 'state' => $state, 'district' => $district, 'zipcode' => '40132'],
                'item_list' => [['item_id' => $line->item_id, 'item_name' => $line->name, 'model_id' => $line->model_id, 'model_name' => $line->model_name, 'model_sku' => $line->sku, 'model_quantity_purchased' => $qty, 'model_discounted_price' => $line->price]],
                'total_amount' => $line->price * $qty + $ship, 'estimated_shipping_fee' => $ship, 'shipping_carrier' => $courier,
            ]);
            if ($status !== 'READY_TO_SHIP') {
                $sub = $line->price * $qty;
                $fee = (int) round($sub * 0.08) + (int) round($sub * 0.04) + (int) round($sub * 0.02);
                $order->update([
                    'tracking_number' => 'SPXID0'.(41230000 + $i * 977),
                    'marketplace_fee' => $fee, 'seller_shipping_fee' => $i % 3 ? 0 : 5000,
                    'escrow_amount' => $status === 'COMPLETED' ? $sub - $fee - ($i % 3 ? 0 : 5000) : null,
                ]);
            }
        }

        // One week of Shopee Ads for this shop.
        for ($d = 0; $d < 7; $d++) {
            AdSpend::create(['user_id' => $user->id, 'platform' => 'shopee_ads', 'marketplace_shop_id' => $shop->id, 'spent_on' => now()->subDays($d)->toDateString(), 'amount' => [25000, 30000, 22000, 35000, 28000, 20000, 32000][$d], 'campaign' => 'Contoh Shopee Ads']);
        }

        return $shop->loadCount(['listings', 'orders']);
    }
}
