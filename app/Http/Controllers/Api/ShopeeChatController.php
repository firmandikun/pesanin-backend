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
    public function conversations(Request $request)
    {
        $shops = $this->shops($request);
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
                        'last_text' => self::preview($c['latest_message_type'] ?? 'text', $c['latest_message_content'] ?? []),
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
        MetaChatController::refreshPages($request->user(), $request->boolean('refresh'));
        $channel = $request->query('channel');
        $meta = in_array($channel, [null, '', 'facebook', 'instagram'], true)
            ? MetaChatController::inbox($request->user(), $channel ?: null, $channel ? $request->query('source') : null, $request->boolean('refresh'))
            : ['conversations' => [], 'errors' => []];
        $wa = in_array($channel, [null, '', 'whatsapp'], true) ? WhatsAppChatController::inbox($request->user(), $channel === 'whatsapp' ? $request->query('source') : null) : [];
        $out = array_merge($out, $meta['conversations'], $wa);
        usort($out, fn ($a, $b) => [$b['pinned'], $b['at']] <=> [$a['pinned'], $a['at']]);
        $out = InboxController::decorate($request->user(), $out);

        $all = $request->user()->marketplaceShops()->where('status', 'active')->where('is_demo', false)->get(['id', 'shop_name']);
        $pages = collect(MetaChatController::integration($request->user())?->settings['pages'] ?? []);

        return [
            // Sources the seller can filter by; Meta channels are listed once they can be connected.
            'channels' => [
                ['id' => 'shopee', 'label' => 'Shopee', 'available' => $all->isNotEmpty(), 'sources' => $all->map(fn ($s) => ['id' => $s->id, 'name' => $s->shop_name])->values()],
                ['id' => 'facebook', 'label' => 'Facebook', 'available' => $pages->isNotEmpty(), 'sources' => $pages->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']])->values()],
                ['id' => 'instagram', 'label' => 'Instagram', 'available' => $pages->whereNotNull('ig_id')->isNotEmpty(), 'sources' => $pages->whereNotNull('ig_id')->map(fn ($p) => ['id' => $p['id'], 'name' => '@'.$p['ig_username']])->values()],
                ['id' => 'whatsapp', 'label' => 'WhatsApp', 'available' => (bool) ($waI = WhatsAppChatController::integration($request->user())),
                    'sources' => $waI ? [['id' => $waI->settings['phone_number_id'] ?? '', 'name' => $waI->settings['display_phone'] ?? 'WhatsApp']] : []],
            ],
            'available' => $shops->isNotEmpty() && count($errors) < $shops->count(),
            'reason' => $shops->isEmpty() ? 'Belum ada toko Shopee terhubung.' : ($errors ? implode(' · ', $errors) : null),
            'meta' => ['connected' => (bool) MetaChatController::integration($request->user()), 'errors' => $meta['errors'],
                'configured' => (bool) (config('services.meta.app_id') && config('services.meta.chat_config_id'))],
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
        $messages = collect($r['messages'] ?? [])->map(fn ($m) => [
            'id' => (string) $m['message_id'],
            'from_me' => (int) ($m['from_shop_id'] ?? 0) === (int) $shop->shop_id,
            'type' => $m['message_type'] ?? 'text',
            'text' => self::preview($m['message_type'] ?? 'text', $m['content'] ?? []),
            'image' => $m['content']['url'] ?? null,
            'at' => self::time($m['created_timestamp'] ?? null),
        ])->sortBy('at')->values();
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

    private function own(Request $request, MarketplaceShop $shop): void
    {
        abort_unless($shop->user_id === $request->user()->id, 404);
    }

    /** Text for a message bubble or list preview; non-text messages get a label. */
    public static function preview(string $type, array $content): string
    {
        return match ($type) {
            'text' => (string) ($content['text'] ?? ''),
            'image' => '[Gambar]', 'sticker' => '[Stiker]', 'video' => '[Video]',
            'item', 'product' => '[Produk]', 'order' => '[Pesanan]',
            default => (string) ($content['text'] ?? '[Pesan]'),
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
