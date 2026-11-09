<?php

namespace App\Support;

use App\Models\User;

class DefaultTemplates
{
    public const VARIABLES = [
        'name', 'phone', 'address', 'city', 'district', 'product_name', 'product_variation', 'product_price',
        'order_quantity', 'shipping_cost', 'cod_fee', 'total_price', 'payment_method', 'order_id',
        'courier', 'receipt_number', 'bank_accounts', 'seller_name',
    ];

    public const EVENTS = [
        'welcome' => ['Welcome', "Hai {{name}} 👋 Terima kasih telah memesan {{product_name}} di toko kami!\n\n📦 Produk: {{product_name}} {{product_variation}}\n🔢 Jumlah: {{order_quantity}}\n💰 Harga: {{product_price}}\n🚚 Ongkir: {{shipping_cost}}\n💳 Total: {{total_price}}\n\n📍 Dikirim ke:\n{{name}} ({{phone}})\n{{address}}, {{district}}, {{city}}\n\nSilakan lakukan pembayaran sejumlah {{total_price}} ke salah satu rekening berikut:\n{{bank_accounts}}\n\nTerima kasih!\n{{seller_name}}"],
        'order_detail' => ['Order Detail', "Hai {{name}} 👋 Berikut detail pesanan kamu:\n\nID: {{order_id}}\n📦 {{product_name}} {{product_variation}} x{{order_quantity}}\n🚚 Ongkir: {{shipping_cost}}\n💳 Total: {{total_price}} ({{payment_method}})\n📍 {{address}}, {{district}}, {{city}}\n\nMohon balas *YA* jika data sudah benar 🙏"],
        'followup_1' => ['Follow up 1', "Hai {{name}} 👋 Kami perhatikan pesanan kamu untuk {{product_name}} belum diselesaikan. Ada yang bisa kami bantu?"],
        'followup_2' => ['Follow up 2', "Hai {{name}} 👋 Pesanan kamu untuk {{product_name}} masih belum kami terima pembayarannya. Total {{total_price}} ya kak."],
        'followup_3' => ['Follow up 3', "Hai {{name}} 👋 Kami ingin mengingatkan kembali pesanan kamu untuk {{product_name}}. Stok terbatas lho kak 😊"],
        'followup_4' => ['Follow up 4', "Hai {{name}} 👋 Ini pengingat untuk pesanan {{product_name}} kamu. Kalau ada kendala pembayaran, kabari kami ya."],
        'followup_5' => ['Follow up 5', "Hai {{name}} 👋 Ini pengingat terakhir untuk pesanan {{product_name}}. Pesanan akan kami batalkan jika belum ada konfirmasi hari ini."],
        'processing' => ['Processing', "Hai {{name}} 👋 Pembayaran kamu untuk {{product_name}} sebesar {{total_price}} telah kami terima. Pesanan sedang kami proses 📦"],
        'completed' => ['Completed', "Hai {{name}} 👋 Pesanan kamu atas {{product_name}} sudah kami kirim via {{courier}} 🚚📦\nNo. resi: *{{receipt_number}}*"],
        'upselling' => ['UpSelling', "Hai {{name}} 👋 Terima kasih sudah berbelanja {{product_name}}! Ada promo spesial untuk pelanggan setia, mau info lebih lanjut?"],
        'redirect' => ['Redirect', "Halo {{seller_name}}, saya sudah order *{{product_name}}* {{product_variation}} sebanyak {{order_quantity}} pcs.\nID Pesanan: {{order_id}}\nTotal: {{total_price}} ({{payment_method}})\nNama: {{name}}"],
    ];

    public static function seed(User $user): void
    {
        foreach (self::EVENTS as $event => [$name, $message]) {
            $user->followupTemplates()->create(compact('event', 'name', 'message'));
        }
    }
}
