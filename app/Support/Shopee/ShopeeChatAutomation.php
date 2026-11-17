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

    /**
     * Send a single unpaid reminder chat to a buyer on Shopee.
     */
    public static function remindOrder(Order $order, ?string $customMessage = null): array
    {
        $shop = $order->marketplaceShop;
        if (! $shop || $shop->is_demo) {
            throw new \RuntimeException('Toko Shopee tidak aktif atau toko contoh.');
        }

        $custom = (array) ($order->custom_fields ?? []);
        $buyerUserId = $custom['buyer_user_id'] ?? null;
        $buyerName = $custom['buyer_username'] ?? $order->customer_name ?? 'Kak';
        $orderSn = $order->external_order_sn ?: $order->code;

        // Fallback 1: Query Shopee order detail to fetch buyer_user_id if missing
        if (! $buyerUserId && $order->external_order_sn) {
            try {
                $details = (new ShopeeClient)->orderDetail($shop, [$order->external_order_sn]);
                if (! empty($details[0]['buyer_user_id'])) {
                    $buyerUserId = (int) $details[0]['buyer_user_id'];
                    $custom['buyer_user_id'] = $buyerUserId;
                    if (! empty($details[0]['buyer_username'])) {
                        $buyerName = $details[0]['buyer_username'];
                        $custom['buyer_username'] = $buyerName;
                    }
                    $order->update(['custom_fields' => $custom]);
                }
            } catch (\Throwable) {
                // proceed to next fallback
            }
        }

        // Fallback 2: Match buyer_username from existing chat conversations
        if (! $buyerUserId && $buyerName && $buyerName !== 'Kak') {
            try {
                $convs = (new ShopeeClient)->chatConversations($shop);
                foreach ($convs['conversations'] ?? [] as $c) {
                    if (strcasecmp((string) ($c['to_name'] ?? ''), (string) $buyerName) === 0 && ! empty($c['to_id'])) {
                        $buyerUserId = (int) $c['to_id'];
                        $custom['buyer_user_id'] = $buyerUserId;
                        $order->update(['custom_fields' => $custom]);
                        break;
                    }
                }
            } catch (\Throwable) {
                // proceed
            }
        }

        if (! $buyerUserId) {
            throw new \RuntimeException("Buyer User ID Shopee tidak ditemukan pada pesanan {$orderSn}.");
        }

        $user = $shop->user;
        $cfg = $user ? self::settings($user) : ['unpaid_text' => self::DEFAULT_UNPAID_TEXT];

        $tmpl = $customMessage ?: ($cfg['unpaid_text'] ?: self::DEFAULT_UNPAID_TEXT);
        $text = str_replace(
            ['{{nama}}', '{{order_sn}}', '{nama}', '{order_sn}'],
            [$buyerName, $orderSn, $buyerName, $orderSn],
            $tmpl
        );

        (new ShopeeClient)->chatSend($shop, (int) $buyerUserId, $text);
        $order->increment('followup_count');

        return [
            'ok' => true,
            'buyer' => $buyerName,
            'order_sn' => $orderSn,
            'text' => $text,
        ];
    }

    /**
     * Scan recent unpaid orders for a shop and send reminders if enabled.
     */
    public static function checkPendingUnpaidOrders(MarketplaceShop $shop): int
    {
        if ($shop->is_demo) {
            return 0;
        }

        $user = $shop->user;
        if (! $user) {
            return 0;
        }

        $cfg = self::settings($user);
        if (! $cfg['unpaid_reminder']) {
            return 0;
        }

        $orders = Order::where('marketplace_shop_id', $shop->id)
            ->where('channel', 'shopee')
            ->where(function ($q) {
                $q->where('external_status', 'UNPAID')
                    ->orWhere('payment_status', 'unpaid');
            })
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        $sent = 0;
        foreach ($orders as $order) {
            $lockKey = "shopee:auto_chat:unpaid:{$order->id}";
            if (Cache::add($lockKey, 1, now()->addDays(7))) {
                try {
                    self::remindOrder($order);
                    $sent++;
                } catch (\Throwable $e) {
                    Cache::forget($lockKey);
                    Log::warning("Shopee auto-remind failed for order {$order->id}: {$e->getMessage()}");
                }
            }
        }

        return $sent;
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
