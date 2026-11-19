<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\ShopeeReview;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Telegram\TelegramClient;
use Carbon\Carbon;
use Throwable;

/**
 * Ulasan pembeli Shopee: pulled from v2.product.get_comment (Shopee serves the newest 500), replied from Pesanin —
 * one by one, in bulk with a template per star rating, or automatically for good reviews. Low ratings are alerted.
 * A review can be answered once (Shopee refuses a second reply).
 */
class ShopeeReviews
{
    /** Default reply templates; {nama} = buyer username, {produk} = product name. */
    public const TEMPLATES = [
        'good' => 'Terima kasih banyak kak {nama} atas ulasannya! Senang produknya sesuai harapan. Ditunggu order berikutnya ya 🙏',
        'mid' => 'Terima kasih kak {nama} atas ulasannya. Masukan kakak sangat berarti untuk kami agar lebih baik lagi 🙏',
        'low' => 'Mohon maaf atas ketidaknyamanannya kak {nama}. Silakan chat kami ya, kami bantu sampai tuntas 🙏',
    ];

    public static function settings(User $user): array
    {
        $s = ($user->settings['reviews'] ?? []) + ['auto_reply' => false, 'auto_min_rating' => 5, 'alert_max_rating' => 3, 'templates' => []];
        $s['templates'] = array_filter((array) $s['templates']) + self::TEMPLATES;
        $s['auto_min_rating'] = in_array((int) $s['auto_min_rating'], [4, 5], true) ? (int) $s['auto_min_rating'] : 5;
        $s['alert_max_rating'] = max(0, min(5, (int) $s['alert_max_rating']));

        return $s;
    }

    public static function templateFor(array $settings, int $rating): string
    {
        return $settings['templates'][$rating >= 4 ? 'good' : ($rating === 3 ? 'mid' : 'low')];
    }

    /** Fill {nama} / {produk}. Keeps the reply within Shopee's limit. */
    public static function fill(string $template, ShopeeReview $r, ?string $product = null): string
    {
        return mb_substr(strtr($template, ['{nama}' => $r->buyer ?: 'kak', '{produk}' => $product ?: 'produk kami']), 0, 500);
    }

    /** Pull reviews of one shop (newest first, stops at reviews already stored and unchanged). @return ShopeeReview[] new ones */
    public static function sync(MarketplaceShop $shop, int $maxPages = 5): array
    {
        $api = new ShopeeClient;
        $new = [];
        $cursor = '';
        for ($page = 0; $page < $maxPages; $page++) {
            $r = $api->comments($shop, $cursor, 100);
            $known = 0;
            foreach ($r['item_comment_list'] ?? [] as $c) {
                $review = ShopeeReview::firstOrNew(['marketplace_shop_id' => $shop->id, 'comment_id' => (int) $c['comment_id']]);
                $isNew = ! $review->exists;
                $review->user_id = $shop->user_id;
                $reply = $c['comment_reply'] ?? null;
                $review->fill([
                    'item_id' => (int) ($c['item_id'] ?? 0), 'order_sn' => $c['order_sn'] ?? null, 'buyer' => $c['buyer_username'] ?? null,
                    'rating' => (int) ($c['rating_star'] ?? 0), 'comment' => $c['comment'] ?? null, 'hidden' => (bool) ($c['hidden'] ?? false),
                    'media' => array_filter(['images' => $c['media']['image_url_list'] ?? [], 'videos' => $c['media']['video_url_list'] ?? []]) ?: null,
                    'reviewed_at' => isset($c['create_time']) ? Carbon::createFromTimestamp($c['create_time']) : null,
                ] + (! empty($reply['reply']) ? ['reply' => $reply['reply'], 'replied_at' => isset($reply['create_time']) ? Carbon::createFromTimestamp($reply['create_time']) : now(), 'reply_error' => null] : []));
                $known += $isNew ? 0 : 1;
                $review->save();
                if ($isNew) {
                    $new[] = $review;
                }
            }
            $cursor = (string) ($r['next_cursor'] ?? '');
            // Newest first: a page with nothing new means the rest is stored already.
            if (empty($r['more']) || $cursor === '' || ($known > 0 && $known === count($r['item_comment_list'] ?? []))) {
                break;
            }
        }

        return $new;
    }

    /**
     * Reply to reviews (same shop or several). @param array<int,string> $texts review id => text
     * @return array{sent: int, failed: array<int,string>}
     */
    public static function reply(User $user, array $texts): array
    {
        $reviews = ShopeeReview::where('user_id', $user->id)->whereIn('id', array_keys($texts))->whereNull('replied_at')->with('shop')->get();
        $api = new ShopeeClient;
        [$sent, $failed] = [0, []];
        foreach ($reviews->groupBy('marketplace_shop_id') as $group) {
            $shop = $group->first()->shop;
            foreach ($group->chunk(100) as $chunk) {
                $payload = $chunk->mapWithKeys(fn ($r) => [$r->comment_id => trim($texts[$r->id])])->filter()->all();
                try {
                    $refused = $shop->is_demo ? [] : $api->replyComments($shop, $payload);
                } catch (Throwable $e) {
                    $refused = array_fill_keys(array_map('strval', array_keys($payload)), mb_substr($e->getMessage(), 0, 180));
                }
                foreach ($chunk as $r) {
                    if (! isset($payload[$r->comment_id])) {
                        continue;
                    }
                    if ($why = $refused[(string) $r->comment_id] ?? null) {
                        $r->update(['reply_error' => mb_substr($why, 0, 190)]);
                        $failed[$r->id] = $why;
                    } else {
                        $r->update(['reply' => $payload[$r->comment_id], 'replied_at' => now(), 'reply_error' => null]);
                        $sent++;
                    }
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Hourly: sync every shop, auto-reply good reviews if enabled, alert on new low ratings. @return int new reviews */
    public static function run(User $user): int
    {
        $cfg = self::settings($user);
        $new = [];
        $shops = $user->marketplaceShops()->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false)->get();
        foreach ($shops as $shop) {
            try {
                $new = [...$new, ...self::sync($shop)];
            } catch (Throwable) {
                continue;
            }
        }
        if (! $new) {
            return 0;
        }
        $names = MarketplaceListing::whereIn('item_id', collect($new)->pluck('item_id')->unique())->pluck('name', 'item_id');
        if ($cfg['auto_reply']) {
            $texts = collect($new)->filter(fn ($r) => ! $r->replied_at && $r->rating >= $cfg['auto_min_rating'])
                ->mapWithKeys(fn ($r) => [$r->id => self::fill(self::templateFor($cfg, $r->rating), $r, $names[$r->item_id] ?? null)])->all();
            if ($texts) {
                self::reply($user, $texts);
            }
        }
        $low = collect($new)->filter(fn ($r) => $r->rating > 0 && $r->rating <= $cfg['alert_max_rating']);
        if ($low->isNotEmpty()) {
            $shopNames = $shops->pluck('shop_name', 'id');
            $multi = $shops->count() > 1;
            $lines = $low->take(10)->map(fn ($r) => '• '.str_repeat('★', $r->rating).' '.($multi ? '['.($shopNames[$r->marketplace_shop_id] ?? 'Toko').'] ' : '').mb_strimwidth($names[$r->item_id] ?? 'Produk', 0, 40, '…').': "'.mb_strimwidth((string) $r->comment, 0, 80, '…').'"')->implode("\n");
            $title = $low->count().' ulasan bintang rendah baru';
            Notifier::send($user->id, 'review', $title, $lines, '/marketplace/reviews?rating=low');
            try {
                $i = $user->integration('telegram');
                if ($i && $i->key('bot_token') && ! empty($i->settings['chat_id'])) {
                    (new TelegramClient($i->key('bot_token')))->sendMessage($i->settings['chat_id'], "⭐ {$title}\n{$lines}\n\nBalas di Pesanin → Marketplace → Ulasan.");
                }
            } catch (Throwable) {
                // in-app notification already sent
            }
        }

        return count($new);
    }
}
