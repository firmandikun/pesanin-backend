<?php

namespace App\Support\Shopee;

use App\Http\Controllers\Api\ShopeeChatController;
use App\Models\MarketplaceShop;
use App\Support\Notifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * New buyer chat → notification (bell + Web Push), like new orders. Runs with the 30s Shopee poll.
 * The first check of a shop only remembers what is already there, so old chats don't flood the bell.
 * If Shopee refuses (chat permission not granted), it backs off for 30 minutes instead of retrying every 30s.
 */
class ShopeeChatWatch
{
    public static function check(MarketplaceShop $shop, ?ShopeeClient $api = null): int
    {
        if ($shop->is_demo || Cache::has("shopee:chat:off:{$shop->id}")) {
            return 0;
        }
        try {
            $conversations = ($api ?? new ShopeeClient)->chatConversations($shop)['conversations'] ?? [];
        } catch (Throwable $e) {
            Cache::put("shopee:chat:off:{$shop->id}", true, now()->addMinutes(30));
            Log::warning("Notifikasi chat Shopee dijeda 30 menit untuk toko {$shop->id} ({$shop->shop_name}): {$e->getMessage()}");

            return 0;
        }
        $primed = Cache::has("shopee:chat:primed:{$shop->id}");
        $sent = 0;
        foreach ($conversations as $c) {
            $lastId = (string) ($c['latest_message_id'] ?? '');
            $fromBuyer = (int) ($c['latest_message_from_id'] ?? 0) !== (int) $shop->shop_id;
            $key = "shopee:chat:seen:{$shop->id}:{$c['conversation_id']}";
            if ($lastId === '' || Cache::get($key) === $lastId) {
                continue;
            }
            Cache::forever($key, $lastId);
            // unread_count missing in some responses: a new buyer message counts as unread.
            if ($primed && $fromBuyer && (int) ($c['unread_count'] ?? 1) > 0 && self::notify($shop, $c, $lastId)) {
                $sent++;
            }
        }
        Cache::forever("shopee:chat:primed:{$shop->id}", true);

        return $sent;
    }

    /**
     * Notify one buyer message once (shared with chat auto-reply, which may answer before this poll sees it).
     * @return bool sent now
     */
    public static function notify(MarketplaceShop $shop, array $c, string $messageId, bool $autoReplied = false): bool
    {
        if (! Cache::add("shopee:chat:notified:{$shop->id}:{$messageId}", 1, now()->addDays(3))) {
            return false;
        }
        $text = ShopeeChatController::preview($c['latest_message_type'] ?? 'text', (array) ($c['latest_message_content'] ?? []));
        Notifier::send($shop->user_id, 'chat', $autoReplied ? 'Chat Shopee baru (dibalas otomatis)' : 'Chat Shopee baru',
            mb_strimwidth(($c['to_name'] ?? 'Pembeli').': '.($text ?: 'Pesan'), 0, 120, '…').' · '.$shop->shop_name,
            "/chat?channel=shopee&source={$shop->id}&c={$c['conversation_id']}");

        return true;
    }
}
