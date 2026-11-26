<?php

namespace App\Support\Shopee;

use App\Http\Controllers\Api\ShopeeChatController;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Shopee chat automation (Chat → Balasan Cepat & Otomatisasi → Otomatisasi Shopee): automatic REPLIES only.
 * Pesanin never starts a conversation: Shopee treats seller-initiated, repeated or off-order messages (and asking
 * for ratings) as chat spam, with penalties up to a chat ban. So only a fresh message the buyer sent is answered:
 *  - keyword answers (e.g. "resi" → tracking number of the buyer's latest order);
 *  - an away message outside working hours.
 * Each buyer message is answered at most once, the same keyword answer at most every 10 minutes per buyer and the
 * away message once per buyer per night, so a conversation never loops.
 */
class ChatAutomation
{
    public const KEY = 'shopee_chat_automation';

    /** Variables usable in every template. */
    public const VARIABLES = ['{nama}', '{toko}', '{order_sn}', '{resi}', '{kurir}', '{status}'];

    public const STATUS = [
        'UNPAID' => 'menunggu pembayaran', 'READY_TO_SHIP' => 'sedang dikemas', 'PROCESSED' => 'sedang dikemas',
        'RETRY_SHIP' => 'sedang dikemas', 'SHIPPED' => 'dalam pengiriman', 'TO_CONFIRM_RECEIVE' => 'sudah sampai',
        'COMPLETED' => 'selesai', 'IN_CANCEL' => 'proses pembatalan', 'CANCELLED' => 'dibatalkan', 'TO_RETURN' => 'proses pengembalian',
    ];

    public static function settings(User $user): array
    {
        $s = (array) ($user->settings[self::KEY] ?? []);
        $away = (array) ($s['away'] ?? []);

        return [
            'away' => [
                'enabled' => (bool) ($away['enabled'] ?? false),
                'start' => self::hhmm($away['start'] ?? '21:00', '21:00'),
                'end' => self::hhmm($away['end'] ?? '08:00', '08:00'),
                'text' => (string) ($away['text'] ?? ''),
            ],
            'keywords_enabled' => (bool) ($s['keywords_enabled'] ?? false),
            'keywords' => collect($s['keywords'] ?? [])->filter(fn ($k) => is_array($k) && trim((string) ($k['words'] ?? '')) !== '' && trim((string) ($k['text'] ?? '')) !== '')
                ->map(fn ($k) => ['words' => (string) $k['words'], 'text' => (string) $k['text']])->take(20)->values()->all(),
        ];
    }

    /** Validation rules for saving the settings (FeatureController::updateUserSettings). */
    public static function rules(): array
    {
        return [
            'value.away' => 'nullable|array', 'value.away.enabled' => 'boolean', 'value.away.start' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'value.away.end' => ['nullable', 'regex:/^\d{2}:\d{2}$/'], 'value.away.text' => 'nullable|string|max:1000',
            'value.keywords_enabled' => 'boolean', 'value.keywords' => 'nullable|array|max:20',
            'value.keywords.*.words' => 'required|string|max:200', 'value.keywords.*.text' => 'required|string|max:1000',
        ];
    }

    private static function hhmm(mixed $v, string $default): string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $default;
    }

    /** Outside working hours? The window may cross midnight (21:00 → 08:00). */
    public static function isAway(array $away, ?Carbon $now = null): bool
    {
        $t = ($now ?? now('Asia/Jakarta'))->setTimezone('Asia/Jakarta')->format('H:i');

        return $away['start'] <= $away['end']
            ? $t >= $away['start'] && $t < $away['end']
            : $t >= $away['start'] || $t < $away['end'];
    }

    /** The keyword rule a buyer message triggers (first match wins; words are comma separated, case-insensitive; "resinya" matches "resi"). */
    public static function keywordFor(array $rules, string $text): ?array
    {
        $text = mb_strtolower($text);
        foreach ($rules as $r) {
            foreach (preg_split('/\s*,\s*/', mb_strtolower($r['words'])) as $w) {
                if ($w !== '' && preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($w, '/').'(nya|ny|kah|lah)?($|[^\p{L}\p{N}])/u', $text)) {
                    return $r;
                }
            }
        }

        return null;
    }

    public static function fill(string $template, array $vars): string
    {
        return trim(strtr($template, [
            '{nama}' => $vars['nama'] ?? 'Kak', '{toko}' => $vars['toko'] ?? '',
            '{order_sn}' => $vars['order_sn'] ?? '-', '{no_pesanan}' => $vars['order_sn'] ?? '-',
            '{resi}' => $vars['resi'] ?? 'belum terbit', '{kurir}' => $vars['kurir'] ?? '-', '{status}' => $vars['status'] ?? '-',
        ]));
    }

    /** Template variables of an order (and its buyer). */
    public static function orderVars(?Order $o, ?string $name = null, ?MarketplaceShop $shop = null): array
    {
        return [
            'nama' => $name ?: ($o?->custom_fields['buyer_username'] ?? null) ?: ($o?->customer_name ?: 'Kak'),
            'toko' => $shop?->shop_name ?? '',
            'order_sn' => $o?->external_order_sn,
            'resi' => $o?->tracking_number ?: null,
            'kurir' => $o?->courier ?: null,
            'status' => $o ? (self::STATUS[$o->external_status] ?? strtolower((string) $o->external_status)) : null,
        ];
    }

    /** Latest order of a Shopee buyer in this shop. */
    public static function latestOrder(MarketplaceShop $shop, int $buyerId): ?Order
    {
        return $buyerId > 0 ? Order::where('marketplace_shop_id', $shop->id)
            ->where(fn ($q) => $q->where('custom_fields->buyer_user_id', $buyerId)->orWhere('custom_fields->buyer_user_id', (string) $buyerId))
            ->latest('id')->first() : null;
    }

    /** One pass for a store. @return array{replies: int} */
    public static function run(User $user, ?ShopeeClient $api = null): array
    {
        $cfg = self::settings($user);
        $out = ['replies' => 0];
        $chatOn = ($cfg['away']['enabled'] && trim($cfg['away']['text']) !== '') || ($cfg['keywords_enabled'] && $cfg['keywords']);
        if (! $chatOn) {
            return $out;
        }
        $api ??= new ShopeeClient;
        $shops = $user->marketplaceShops()->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false)->get();
        foreach ($shops as $shop) {
            try {
                $out['replies'] += self::autoReply($shop, $cfg, $api);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    /** Keyword answers and the away message for new buyer messages. */
    private static function autoReply(MarketplaceShop $shop, array $cfg, ShopeeClient $api): int
    {
        $sent = 0;
        $r = $api->chatConversations($shop, 25, 'unread');
        foreach ($r['conversations'] ?? [] as $c) {
            $fromBuyer = isset($c['latest_message_from_id']) && (int) $c['latest_message_from_id'] !== (int) $shop->shop_id;
            $msgId = (string) ($c['latest_message_id'] ?? '');
            $ts = (int) ($c['last_message_timestamp'] ?? 0);
            $ts = $ts > 1e12 ? intdiv($ts, $ts > 1e15 ? 1000000000 : 1000) : $ts; // nano/milli → seconds
            $toId = (int) ($c['to_id'] ?? 0);
            // Only fresh messages (not a backlog from before the feature was on), once per message.
            if (! $fromBuyer || ! $toId || $msgId === '' || ($ts && $ts < time() - 15 * 60)
                || ! Cache::add("chat_auto:{$shop->id}:{$msgId}", 1, now()->addDays(2))) {
                continue;
            }
            $text = ShopeeChatController::preview($c['latest_message_type'] ?? 'text', (array) ($c['latest_message_content'] ?? []));
            $reply = null;
            if ($cfg['keywords_enabled'] && ($rule = self::keywordFor($cfg['keywords'], $text))) {
                // Same answer to the same buyer at most every 10 minutes.
                if (Cache::add("chat_auto_kw:{$shop->id}:{$toId}:".md5($rule['words']), 1, now()->addMinutes(10))) {
                    $reply = $rule['text'];
                }
            } elseif ($cfg['away']['enabled'] && trim($cfg['away']['text']) !== '' && self::isAway($cfg['away'])) {
                // Away message once per buyer per night.
                if (Cache::add("chat_auto_away:{$shop->id}:{$toId}", 1, now()->addHours(10))) {
                    $reply = $cfg['away']['text'];
                }
            }
            if ($reply === null) {
                continue;
            }
            $vars = self::orderVars(self::latestOrder($shop, $toId), $c['to_name'] ?? null, $shop);
            try {
                $api->chatSend($shop, $toId, self::fill($reply, $vars));
                $sent++;
                // The reply makes the seller the last sender, so the chat poll would no longer see this buyer message.
                ShopeeChatWatch::notify($shop, $c, $msgId, true);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}
