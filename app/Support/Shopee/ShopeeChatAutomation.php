<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Automations for Shopee Chat:
 * 1. Unpaid payment reminder before deadline expires.
 * 2. Delivered / Completed friendly thank-you & 5-star review request.
 */
class ShopeeChatAutomation
{
    public const DEFAULT_UNPAID_TEXT = "Halo Kak {{nama}}, pesananmu ({{order_sn}}) sudah kami siapkan ya kak. Yuk segera selesaikan pembayarannya agar paket bisa langsung kami kirimkan hari ini ya kak 🙏";

    public const DEFAULT_DELIVERED_TEXT = "Halo Kak {{nama}}, pesananmu ({{order_sn}}) sudah sampai ya. Mohon periksa kelengkapan produknya. Jika sudah sesuai, mohon bantu klik 'Pesanan Diterima' dan berikan ulasan bintang 5 ⭐⭐⭐⭐⭐ ya kak. Terima kasih banyak! 🙏";

    public static function settings(User $user): array
    {
        $cfg = $user->settings['shopee_chat_automation'] ?? [];

        return [
            'unpaid_reminder' => (bool) ($cfg['unpaid_reminder'] ?? false),
            'unpaid_text' => (string) ($cfg['unpaid_text'] ?? self::DEFAULT_UNPAID_TEXT),
            'delivered_review' => (bool) ($cfg['delivered_review'] ?? false),
            'delivered_text' => (string) ($cfg['delivered_text'] ?? self::DEFAULT_DELIVERED_TEXT),
        ];
    }

    public static function onOrderUpdated(MarketplaceShop $shop, Order $order, ?string $prevStatus = null): void
    {
        if ($shop->is_demo) {
            return;
        }

        $user = $shop->user;
        if (! $user) {
            return;
        }

        $cfg = self::settings($user);
        $custom = (array) ($order->custom_fields ?? []);
        $buyerUserId = $custom['buyer_user_id'] ?? null;
        if (! $buyerUserId) {
            return;
        }

        $buyerName = $custom['buyer_username'] ?? $order->customer_name ?? 'Kak';
        $orderSn = $order->external_order_sn ?: $order->code;

        $render = fn (string $tmpl) => str_replace(
            ['{{nama}}', '{{order_sn}}', '{nama}', '{order_sn}'],
            [$buyerName, $orderSn, $buyerName, $orderSn],
            $tmpl
        );

        // 1. Unpaid Reminder
        if ($cfg['unpaid_reminder'] && ($order->external_status === 'UNPAID' || $order->payment_status === 'unpaid')) {
            $lockKey = "shopee:auto_chat:unpaid:{$order->id}";
            if ($order->created_at && $order->created_at->gt(now()->subHours(24)) && Cache::add($lockKey, 1, now()->addDays(7))) {
                try {
                    $text = $render($cfg['unpaid_text'] ?: self::DEFAULT_UNPAID_TEXT);
                    (new ShopeeClient)->chatSend($shop, (int) $buyerUserId, $text);
                } catch (\Throwable $e) {
                    Log::warning("Shopee unpaid auto-reminder failed for order {$order->id}: {$e->getMessage()}");
                }
            }
        }

        // 2. Delivered / Completed Review Request
        if ($cfg['delivered_review'] && ($order->external_status === 'COMPLETED' || $order->shipping_status === 'delivered')) {
            $lockKey = "shopee:auto_chat:delivered:{$order->id}";
            if (Cache::add($lockKey, 1, now()->addDays(30))) {
                try {
                    $text = $render($cfg['delivered_text'] ?: self::DEFAULT_DELIVERED_TEXT);
                    (new ShopeeClient)->chatSend($shop, (int) $buyerUserId, $text);
                } catch (\Throwable $e) {
                    Log::warning("Shopee delivered review request failed for order {$order->id}: {$e->getMessage()}");
                }
            }
        }
    }
}
