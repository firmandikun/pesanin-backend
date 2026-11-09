<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\Meta\MetaAds;
use App\Support\Meta\MetaChat;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chat from Facebook Pages (Messenger) and their Instagram accounts (Direct), in the shared /chat inbox.
 * Connection: "Login dengan Facebook" with the chat login configuration → Page tokens stored encrypted.
 */
class MetaChatController extends Controller
{
    public const PLATFORMS = ['facebook' => 'messenger', 'instagram' => 'instagram'];

    public function status(Request $request)
    {
        $i = self::integration($request->user());

        return [
            'configured' => (bool) (config('services.meta.app_id') && config('services.meta.app_secret') && config('services.meta.chat_config_id')),
            'connected' => (bool) $i,
            'name' => $i?->settings['name'] ?? null,
            'pages' => collect($i?->settings['pages'] ?? [])->map(fn ($p) => array_intersect_key($p, array_flip(['id', 'name', 'picture', 'ig_id', 'ig_username'])))->values(),
            'error' => $i?->last_error,
        ];
    }

    public function connect(Request $request)
    {
        abort_unless(config('services.meta.app_id') && config('services.meta.chat_config_id'), 422, 'Isi META_APP_ID, META_APP_SECRET dan META_CHAT_CONFIG_ID di backend/.env dulu.');
        $state = Crypt::encryptString(json_encode(['uid' => $request->user()->id, 'kind' => 'chat', 'exp' => now()->addMinutes(15)->timestamp]));

        return ['url' => 'https://www.facebook.com/'.config('services.meta.graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.app_id'), 'redirect_uri' => config('services.meta.redirect'), 'state' => $state,
            'response_type' => 'code', 'config_id' => config('services.meta.chat_config_id'), 'override_default_response_type' => 'true',
        ])];
    }

    /** Called from the shared /api/meta/callback once the code is exchanged. */
    public static function saveFromToken(User $user, string $token): UserIntegration
    {
        $res = Http::get(MetaAds::graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token', 'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'), 'fb_exchange_token' => $token,
        ]);
        $token = $res->json('access_token') ?: $token; // long-lived user token → Page tokens that don't expire
        $me = Http::get(MetaAds::graph('me'), ['fields' => 'id,name', 'access_token' => $token])->json();
        $pages = MetaChat::pages($token);

        $i = UserIntegration::firstOrNew(['user_id' => $user->id, 'provider' => 'meta_chat']);
        $i->forceFill([
            'credentials' => ['access_token' => $token, 'pages' => collect($pages)->mapWithKeys(fn ($p) => [$p['id'] => $p['token']])->all()],
            'status' => 'active', 'is_production' => true, 'last_error' => $pages ? null : 'Tidak ada Halaman Facebook yang diizinkan. Hubungkan ulang dan pilih Halamanmu.', 'verified_at' => now(),
            'webhook_token' => $i->webhook_token ?? Str::random(40),
            'settings' => ['name' => $me['name'] ?? null, 'fb_user_id' => $me['id'] ?? null,
                'pages' => collect($pages)->map(fn ($p) => array_intersect_key($p, array_flip(['id', 'name', 'picture', 'ig_id', 'ig_username'])))->values()->all()],
        ])->save();
        ActivityLog::record($user, 'integration.connect', 'Menghubungkan chat Facebook & Instagram ('.count($pages).' Halaman)');

        return $i;
    }

    public function disconnect(Request $request)
    {
        $i = self::integration($request->user());
        if ($i && $i->key('access_token')) {
            try {
                Http::timeout(15)->delete(MetaAds::graph('me/permissions'), ['access_token' => $i->key('access_token')]);
            } catch (Throwable) {
            }
        }
        $i?->delete();

        return response()->noContent();
    }

    /** Conversations for the inbox, in the shared Conv shape. Errors per page are collected, not thrown. */
    public static function inbox(User $user, ?string $channel = null, ?string $source = null, bool $fresh = false): array
    {
        $i = self::integration($user);
        if (! $i) {
            return ['conversations' => [], 'errors' => []];
        }
        // Instagram's conversations endpoint is slow (~10s), so the inbox is kept for 60s. The 30s chat watcher
        // refreshes it in the background (fresh = true), so opening Chat is usually instant.
        $key = "meta:chat:inbox:{$i->id}:".($channel ?? 'all').':'.($source ?? 'all');
        if (! $fresh && ($hit = \Illuminate\Support\Facades\Cache::get($key))) {
            return $hit;
        }
        $result = self::fetchInbox($i, $channel, $source);
        \Illuminate\Support\Facades\Cache::put($key, $result, now()->addSeconds(60));

        return $result;
    }

    private static function fetchInbox(UserIntegration $i, ?string $channel, ?string $source): array
    {
        $tokens = $i->credentials['pages'] ?? [];
        // Every (Page, platform) pair is fetched in parallel: one slow Page no longer delays the others.
        $jobs = [];
        foreach ($i->settings['pages'] ?? [] as $page) {
            if ($source && $source !== $page['id']) {
                continue;
            }
            foreach (self::PLATFORMS as $ch => $platform) {
                if (($channel && $channel !== $ch) || ($ch === 'instagram' && ! $page['ig_id']) || ! isset($tokens[$page['id']])) {
                    continue;
                }
                $jobs["{$page['id']}:{$ch}"] = [$page, $ch, $platform];
            }
        }
        $responses = $jobs ? Http::pool(fn ($pool) => collect($jobs)->map(fn ($j, $key) => $pool->as($key)->timeout(30)
            ->get(MetaAds::graph("{$j[0]['id']}/conversations"), MetaChat::conversationQuery($j[2], $tokens[$j[0]['id']])))->all()) : [];

        $out = [];
        $errors = [];
        foreach ($jobs as $key => [$page, $ch]) {
            $self = $ch === 'instagram' ? $page['ig_id'] : $page['id'];
            $label = $ch === 'instagram' ? '@'.$page['ig_username'] : $page['name'];
            try {
                $res = $responses[$key];
                if ($res instanceof Throwable) {
                    throw $res;
                }
                foreach (MetaChat::unwrap($res)['data'] ?? [] as $c) {
                    $buyer = collect($c['participants']['data'] ?? [])->first(fn ($p) => (string) $p['id'] !== (string) $self && (string) $p['id'] !== $page['id']) ?? [];
                    $last = $c['messages']['data'][0] ?? [];
                    $out[] = [
                        'channel' => $ch, 'source_id' => $page['id'], 'source_name' => $label,
                        'id' => (string) $c['id'], 'to_id' => (string) ($buyer['id'] ?? ''),
                        'name' => $buyer['name'] ?? $buyer['username'] ?? 'Pengguna', 'avatar' => null,
                        'unread' => (int) ($c['unread_count'] ?? 0), 'pinned' => false,
                        'last_text' => self::text($last), 'last_from_me' => (string) ($last['from']['id'] ?? '') === (string) $self,
                        'last_message_id' => $last['id'] ?? null,
                        'at' => isset($c['updated_time']) ? Carbon::parse($c['updated_time'])->toIso8601String() : null,
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = "{$label}: {$e->getMessage()}";
            }
        }

        return ['conversations' => $out, 'errors' => $errors];
    }

    public function messages(Request $request, string $channel, string $page, string $conversation)
    {
        [$token, $self] = $this->page($request, $channel, $page);
        try {
            $rows = MetaChat::messages($conversation, $token);
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }

        return ['messages' => collect($rows)->map(fn ($m) => [
            'id' => (string) $m['id'], 'from_me' => (string) ($m['from']['id'] ?? '') === (string) $self, 'type' => isset($m['attachments']) ? 'attachment' : 'text',
            'text' => self::text($m), 'image' => $m['attachments']['data'][0]['image_data']['url'] ?? null,
            'link' => $m['shares']['data'][0]['link'] ?? $m['story']['link'] ?? null,
            'at' => isset($m['created_time']) ? Carbon::parse($m['created_time'])->toIso8601String() : null,
        ])->sortBy('at')->values()];
    }

    public function send(Request $request, string $channel, string $page)
    {
        [$token] = $this->page($request, $channel, $page);
        $d = $request->validate(['to_id' => 'required|string|max:64', 'text' => 'required|string|max:2000']);
        try {
            $r = MetaChat::send($page, $token, $d['to_id'], $d['text']);
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }

        return ['message' => ['id' => (string) ($r['message_id'] ?? Str::uuid()), 'from_me' => true, 'type' => 'text', 'text' => $d['text'], 'image' => null, 'at' => now()->toIso8601String()]];
    }

    /**
     * Re-read the Pages and their linked Instagram accounts with the stored user token, so a change made in
     * Meta (e.g. linking a new Instagram to a Page) shows up without logging in again. At most every 10 minutes.
     */
    public static function refreshPages(User $user, bool $force = false): void
    {
        $i = self::integration($user);
        if (! $i || ! $i->key('access_token') || (! $force && \Illuminate\Support\Facades\Cache::has("meta:chat:pages:{$i->id}"))) {
            return;
        }
        \Illuminate\Support\Facades\Cache::put("meta:chat:pages:{$i->id}", true, now()->addMinutes(10));
        try {
            $pages = MetaChat::pages($i->key('access_token'));
        } catch (Throwable $e) {
            $i->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);

            return;
        }
        $i->forceFill([
            'credentials' => ['access_token' => $i->key('access_token'), 'pages' => collect($pages)->mapWithKeys(fn ($p) => [$p['id'] => $p['token']])->all()],
            'settings' => array_merge($i->settings ?? [], ['pages' => collect($pages)->map(fn ($p) => array_intersect_key($p, array_flip(['id', 'name', 'picture', 'ig_id', 'ig_username'])))->values()->all()]),
            'last_error' => null,
        ])->save();
    }

    public static function integration(User $user): ?UserIntegration
    {
        return UserIntegration::where('user_id', $user->id)->where('provider', 'meta_chat')->first();
    }

    /** @return array{0: string, 1: string} page token and the id that marks "my" messages */
    private function page(Request $request, string $channel, string $pageId): array
    {
        abort_unless(isset(self::PLATFORMS[$channel]), 404);
        $i = self::integration($request->user());
        $page = collect($i?->settings['pages'] ?? [])->firstWhere('id', $pageId);
        abort_unless($page && isset($i->credentials['pages'][$pageId]), 404);

        return [$i->credentials['pages'][$pageId], $channel === 'instagram' ? (string) $page['ig_id'] : $pageId];
    }

    public static function text(array $m): string
    {
        if (! empty($m['message'])) {
            return (string) $m['message'];
        }
        $mime = (string) ($m['attachments']['data'][0]['mime_type'] ?? '');

        $link = (string) ($m['shares']['data'][0]['link'] ?? '');

        return match (true) {
            $link !== '' => str_contains($link, '/reel/') ? '[Membagikan reel]' : '[Membagikan postingan]',
            isset($m['story']) => '[Membalas story]',
            ! empty($m['is_unsupported']) => '[Pesan tidak didukung — buka di Instagram]',
            isset($m['sticker']) => '[Stiker]',
            str_starts_with($mime, 'image') => '[Gambar]',
            str_starts_with($mime, 'video') => '[Video]',
            str_starts_with($mime, 'audio') => '[Pesan suara]',
            $mime !== '' => '[Lampiran]',
            default => '[Pesan]',
        };
    }
}
