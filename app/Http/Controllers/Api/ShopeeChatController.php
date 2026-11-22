<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceShop;
use App\Support\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Chat (Shopee source): conversations and messages per connected shop through v2.sellerchat.
 * Every Shopee failure is reported as {available:false, reason} so the UI can show why chat is off
 * (typically: Shopee hasn't granted the chat permission to the live app).
 */
class ShopeeChatController extends Controller
{
    /** Chat channels of the store's track: Shopee for Marketplace, Meta & WhatsApp for Social & Web, all with the add-on. */
    public static function channelsFor(\App\Models\User $user): array
    {
        if (\App\Support\Plans::hasAddon($user)) {
            return ['shopee', 'facebook', 'instagram', 'whatsapp'];
        }

        return \App\Support\Plans::track($user) === 'marketplace' ? ['shopee'] : ['facebook', 'instagram', 'whatsapp'];
    }

    public function conversations(Request $request)
    {
        $allowed = self::channelsFor($request->user());
        $shops = in_array('shopee', $allowed, true) ? $this->shops($request) : collect();
        $api = new ShopeeClient;
        $out = [];
        $errors = [];
        foreach ($shops as $shop) {
            try {
                $r = $api->chatConversations($shop);
                foreach ($r['conversations'] ?? [] as $c) {
                    $out[] = [
                        // Inbox is multi-source; `channel` + `source_*` say where a conversation lives.
                        'channel' => 'shopee', 'source_id' => $shop->id, 'source_name' => $shop->shop_name,
                        'shop_id' => $shop->id, 'shop_name' => $shop->shop_name,
                        'id' => (string) $c['conversation_id'], 'to_id' => (int) ($c['to_id'] ?? 0),
                        'name' => $c['to_name'] ?? 'Pembeli', 'avatar' => $c['to_avatar'] ?? null,
                        'unread' => (int) ($c['unread_count'] ?? 0), 'pinned' => (bool) ($c['pinned'] ?? false),
                        'last_text' => self::preview($c['latest_message_type'] ?? 'text', (array) ($c['latest_message_content'] ?? [])) ?: 'Info dari Shopee',
                        'last_from_me' => isset($c['latest_message_from_id']) && (int) $c['latest_message_from_id'] === (int) $shop->shop_id,
                        'last_message_id' => isset($c['latest_message_id']) ? (string) $c['latest_message_id'] : null,
                        'at' => self::time($c['last_message_timestamp'] ?? null),
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = "{$shop->shop_name}: {$e->getMessage()}";
            }
        }
        // Facebook Messenger + Instagram Direct from connected Pages.
        $channel = $request->query('channel');
        $useMeta = in_array('facebook', $allowed, true);
        if ($useMeta) {
            MetaChatController::refreshPages($request->user(), $request->boolean('refresh'));
        }
        $meta = $useMeta && in_array($channel, [null, '', 'facebook', 'instagram'], true)
            ? MetaChatController::inbox($request->user(), $channel ?: null, $channel ? $request->query('source') : null, $request->boolean('refresh'))
            : ['conversations' => [], 'errors' => []];
        $wa = in_array('whatsapp', $allowed, true) && in_array($channel, [null, '', 'whatsapp'], true) ? WhatsAppChatController::inbox($request->user(), $channel === 'whatsapp' ? $request->query('source') : null) : [];
        $out = array_merge($out, $meta['conversations'], $wa);
        usort($out, fn ($a, $b) => [$b['pinned'], $b['at']] <=> [$a['pinned'], $a['at']]);
        $out = InboxController::decorate($request->user(), $out);

        $all = $request->user()->marketplaceShops()->where('status', 'active')->where('is_demo', false)->get(['id', 'shop_name']);
        $pages = collect(MetaChatController::integration($request->user())?->settings['pages'] ?? []);

        return [
            // Sources the seller can filter by; Meta channels are listed once they can be connected.
            'allowed_channels' => $allowed,
            'channels' => array_values(array_filter([
                ['id' => 'shopee', 'label' => 'Shopee', 'available' => $all->isNotEmpty(), 'sources' => $all->map(fn ($s) => ['id' => $s->id, 'name' => $s->shop_name])->values()],
                ['id' => 'facebook', 'label' => 'Facebook', 'available' => $pages->isNotEmpty(), 'sources' => $pages->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']])->values()],
                ['id' => 'instagram', 'label' => 'Instagram', 'available' => $pages->whereNotNull('ig_id')->isNotEmpty(), 'sources' => $pages->whereNotNull('ig_id')->map(fn ($p) => ['id' => $p['id'], 'name' => '@'.$p['ig_username']])->values()],
                ['id' => 'whatsapp', 'label' => 'WhatsApp', 'available' => (bool) ($waI = WhatsAppChatController::integration($request->user())),
                    'sources' => $waI ? [['id' => $waI->settings['phone_number_id'] ?? '', 'name' => $waI->settings['display_phone'] ?? 'WhatsApp']] : []],
            ], fn ($c) => in_array($c['id'], $allowed, true))),
            'available' => in_array('shopee', $allowed, true) ? $shops->isNotEmpty() && count($errors) < $shops->count() : true,
            'reason' => ! in_array('shopee', $allowed, true) ? null : ($shops->isEmpty() ? 'Belum ada toko Shopee terhubung.' : ($errors ? implode(' · ', $errors) : null)),
            'meta' => ['connected' => $useMeta && (bool) MetaChatController::integration($request->user()), 'errors' => $meta['errors'],
                'configured' => $useMeta && (bool) (config('services.meta.app_id') && config('services.meta.chat_config_id'))],
            'whatsapp' => ['connected' => (bool) WhatsAppChatController::integration($request->user())],
            'conversations' => $out,
            'unread' => array_sum(array_column($out, 'unread')),
        ] + InboxController::meta($request->user());
    }

    public function messages(Request $request, MarketplaceShop $shop, string $conversation)
    {
        $this->own($request, $shop);
        try {
            $r = (new ShopeeClient)->chatMessages($shop, $conversation);
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }
        // How far the buyer has read, when Shopee says so (field names vary); otherwise the page infers it from replies.
        $buyerRead = null;
        try {
            $conv = (new ShopeeClient)->call($shop, 'GET', '/api/v2/sellerchat/get_one_conversation', ['conversation_id' => $conversation])['response'] ?? [];
            $buyerRead = collect(['to_last_read_message_id', 'opposite_last_read_message_id', 'buyer_last_read_message_id', 'to_read_message_id'])
                ->map(fn ($k) => $conv[$k] ?? null)->first(fn ($v) => $v !== null && $v !== '' && $v !== 0 && $v !== '0');
        } catch (Throwable) {
        }
        $messages = collect($r['messages'] ?? [])->map(fn ($m) => [
            'id' => (string) $m['message_id'],
            'from_me' => $me = (int) ($m['from_shop_id'] ?? 0) === (int) $shop->shop_id,
            'read' => ! $me ? null : (isset($m['is_read']) ? (bool) $m['is_read'] : (($m['status'] ?? null) === 'read' ? true
                : ($buyerRead !== null ? strcmp(str_pad((string) $m['message_id'], 25, '0', STR_PAD_LEFT), str_pad((string) $buyerRead, 25, '0', STR_PAD_LEFT)) <= 0 : null))),
            'type' => $m['message_type'] ?? 'text',
            'text' => self::preview($m['message_type'] ?? 'text', $m['content'] ?? []),
        ] + self::media($m['message_type'] ?? 'text', (array) ($m['content'] ?? [])) + [
            'at' => self::time($m['created_timestamp'] ?? null),
            'order_sn' => self::orderSn((array) ($m['content'] ?? [])),
            'buyer' => ! ((int) ($m['from_shop_id'] ?? 0) === (int) $shop->shop_id) && (int) ($m['from_id'] ?? 0) > 0,
        ])
            // A message is a chat bubble when it is a known kind or the buyer wrote it (e.g. an order card + question).
            // Shopee's own notices ("Asisten AI Toko telah dijeda", AI/bot hints) are a small centred note when they
            // carry text, and dropped when they have nothing readable (Shopee hides those too).
            ->map(function ($m) {
                $hasOrder = ! empty($m['order_sn']);
                $isBubble = in_array($m['type'], self::BUBBLES, true)
                    || $hasOrder
                    || ($m['buyer'] && ! preg_match('/(^|_)(notif\w*|system\w*|faq|tips?|hint|guide)(_|$)/i', $m['type']));

                return [
                    'system' => ! $isBubble,
                    'text' => $hasOrder && $m['text'] === '' ? '[Pesanan]' : $m['text'],
                ] + $m;
            })
            ->reject(fn ($m) => $m['system'] && $m['text'] === '' && ! $m['image'] && ! $m['video'] && ! $m['order_sn'])
            ->sortBy('at')->values();
        // Order cards: the Pesanin order behind each order number, so the bubble can show and open it.
        $sns = $messages->pluck('order_sn')->filter()->unique()->values()->all();
        $orders = $sns ? \App\Models\Order::where('user_id', $request->user()->id)->where('channel', 'shopee')->whereIn('external_order_sn', $sns)->get()->keyBy('external_order_sn') : collect();
        $messages = $messages->map(function ($m) use ($orders) {
            $o = $m['order_sn'] ? ($orders[$m['order_sn']] ?? null) : null;
            $item = $o ? (collect($o->items ?? [])->first() ?? []) : [];

            return $m + ['order' => $m['order_sn'] ? [
                'sn' => $m['order_sn'], 'id' => $o?->id, 'status' => $o?->external_status, 'total' => $o ? (int) $o->total : null,
                'name' => $item['name'] ?? null, 'image' => $item['image'] ?? null, 'count' => $o ? collect($o->items ?? [])->sum('qty') : null,
            ] : null];
        })->values();
        if ($last = $messages->last()) {
            try {
                (new ShopeeClient)->chatRead($shop, $conversation, $last['id']);
            } catch (Throwable) {
                // marking as read is best effort
            }
        }

        return ['messages' => $messages];
    }

    public function send(Request $request, MarketplaceShop $shop)
    {
        $this->own($request, $shop);
        $data = $request->validate(['to_id' => 'required|integer', 'text' => 'required|string|max:1000']);
        try {
            $r = (new ShopeeClient)->chatSend($shop, (int) $data['to_id'], $data['text']);
        } catch (Throwable $e) {
            abort(422, 'Gagal mengirim chat: '.$e->getMessage());
        }

        return ['message' => ['id' => (string) ($r['message_id'] ?? ''), 'from_me' => true, 'type' => 'text', 'text' => $data['text'], 'image' => null, 'at' => now()->toIso8601String()]];
    }

    private function shops(Request $request)
    {
        return $request->user()->marketplaceShops()->where('status', 'active')->where('is_demo', false)
            ->when($request->query('source') ?? $request->query('shop'), fn ($q, $id) => $q->whereKey($id))
            ->when($request->query('channel') && $request->query('channel') !== 'shopee', fn ($q) => $q->whereRaw('1 = 0'))->get();
    }

    /** Template variables for quick replies: the buyer's latest order in this shop ({order_sn}, {resi}, {kurir}, {status}). */
    public function buyerVars(Request $request, MarketplaceShop $shop, int $toId)
    {
        $this->own($request, $shop);
        $o = \App\Support\Shopee\ChatAutomation::latestOrder($shop, $toId);

        return ['vars' => \App\Support\Shopee\ChatAutomation::orderVars($o, $request->query('name'), $shop), 'order_id' => $o?->id];
    }

    private function own(Request $request, MarketplaceShop $shop): void
    {
        abort_unless($shop->user_id === $request->user()->id, 404);
    }

    /** Photo(s) / video of a message (Shopee names the fields differently per type and version). */
    public static function media(string $type, array $c): array
    {
        $first = fn (array $keys) => collect($keys)->map(fn ($k) => data_get($c, $k))->first(fn ($v) => is_string($v) && $v !== '');
        $video = $type === 'video' ? $first(['video_url', 'url', 'video.url', 'video_info.video_url']) : null;
        $images = match ($type) {
            'video' => array_filter([$first(['thumb_url', 'thumb_image_url', 'cover_url', 'cover_image_url', 'image_url'])]),
            // Product / order cards are not photos the buyer sent.
            'text', 'item', 'product', 'item_list', 'order' => [],
            // Photos, stickers and any other kind (e.g. "image + text" in one message): every image link inside.
            default => self::imageUrls($c),
        };

        return [
            'video' => $video,
            'image' => $images[0] ?? null,
            'images' => array_slice(array_values($images), 0, 9),
            'duration' => $type === 'video' ? (int) ($c['duration_seconds'] ?? $c['duration'] ?? 0) ?: null : null,
        ];
    }

    /** All image links in a content block, wherever Shopee put them (url, image_url_list, images[].url, …). */
    public static function imageUrls(array $c): array
    {
        $out = [];
        $walk = function ($v, $key = '') use (&$walk, &$out) {
            if (is_array($v)) {
                foreach ($v as $k => $x) {
                    $walk($x, is_string($k) ? $k : $key);
                }
            } elseif (is_string($v) && preg_match('#^https?://#i', $v) && ! preg_match('/\.(mp4|mov|m3u8)(\?|$)/i', $v)
                && ! preg_match('/video/i', (string) $key)
                // Prefer the full image over its thumbnail when both are there.
                && (preg_match('#/file/|\.(jpe?g|png|gif|webp)(\?|$)|susercontent|img#i', $v) || preg_match('/url|image|img|pic|photo/i', (string) $key))) {
                $out[] = ['url' => $v, 'thumb' => (bool) preg_match('/thumb/i', (string) $key)];
            }
        };
        $walk($c);
        $full = array_column(array_filter($out, fn ($x) => ! $x['thumb']), 'url');

        return array_values(array_unique($full ?: array_column($out, 'url')));
    }

    /** Send a photo: upload it to Shopee chat, then post it as an image message. */
    public function sendImage(Request $request, MarketplaceShop $shop)
    {
        $this->own($request, $shop);
        $data = $request->validate(['to_id' => 'required|integer', 'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:10240']);
        $api = new ShopeeClient;
        try {
            $url = $api->chatUploadImage($shop, file_get_contents($data['file']->getRealPath()), $data['file']->getClientOriginalName() ?: 'gambar.jpg');
            $r = $api->call($shop, 'POST', '/api/v2/sellerchat/send_message', ['to_id' => (int) $data['to_id'], 'message_type' => 'image', 'content' => ['image_url' => $url]])['response'] ?? [];
        } catch (Throwable $e) {
            abort(422, 'Gagal mengirim gambar: '.$e->getMessage());
        }

        return ['message' => ['id' => (string) ($r['message_id'] ?? ''), 'from_me' => true, 'type' => 'image', 'text' => '[Gambar]', 'image' => $url, 'video' => null, 'at' => now()->toIso8601String()]];
    }

    /** Message kinds Pesanin shows as a chat bubble; anything else is a Shopee notice (AI assistant, system, …). */
    public const BUBBLES = ['text', 'image', 'sticker', 'video', 'item', 'product', 'order', 'item_list'];

    /** Order number a message refers to (order card, order tracking card, …). */
    public static function orderSn(array $c): ?string
    {
        $v = collect(['order_sn', 'order.order_sn', 'order_info.order_sn', 'ordersn', 'order_id_str'])->map(fn ($k) => data_get($c, $k))
            ->first(fn ($v) => is_string($v) && $v !== '');

        return $v ?: null;
    }

    /** Readable text inside any content shape (notices keep it under different keys). */
    public static function contentText(array $c): string
    {
        foreach (['text', 'content', 'title', 'message', 'body', 'sub_title', 'preview_text', 'notification_text', 'desc', 'description', 'tips', 'content.text', 'content.message'] as $k) {
            $v = data_get($c, $k);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    /** Text for a message bubble or list preview; non-text messages get a label. */
    public static function preview(string $type, array $content): string
    {
        return match ($type) {
            'text' => (string) ($content['text'] ?? ''),
            'image' => '[Gambar]', 'sticker' => '[Stiker]', 'video' => '[Video]',
            'item', 'product' => '[Produk]', 'order' => self::contentText($content) ?: '[Pesanan]', 'item_list' => '[Produk]',
            default => self::contentText($content),
        };
    }

    /** Shopee sends seconds or nanoseconds depending on the endpoint. */
    private static function time($ts): ?string
    {
        if (! $ts) {
            return null;
        }
        $ts = (int) $ts;

        return Carbon::createFromTimestamp($ts > 1e15 ? intdiv($ts, 1_000_000_000) : $ts)->toIso8601String();
    }
}
