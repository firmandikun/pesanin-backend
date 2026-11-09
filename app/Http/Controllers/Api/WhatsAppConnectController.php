<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\Meta\MetaAds;
use App\Support\WhatsApp\WhatsAppCloud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Connect WhatsApp Business by signing in with Facebook (no token copy-paste): the login grants
 * access to the seller's WhatsApp Business Accounts, we list their numbers, and the chosen number
 * is saved like the manual integration (token, Phone Number ID, WABA ID) with webhooks subscribed.
 */
class WhatsAppConnectController extends Controller
{
    public function connect(Request $request)
    {
        abort_unless(config('services.meta.app_id') && config('services.meta.wa_config_id'), 422, 'Login WhatsApp belum disiapkan: isi META_WA_CONFIG_ID di backend/.env.');
        $state = Crypt::encryptString(json_encode(['uid' => $request->user()->id, 'kind' => 'whatsapp', 'exp' => now()->addMinutes(15)->timestamp]));

        return ['url' => 'https://www.facebook.com/'.config('services.meta.graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.app_id'), 'redirect_uri' => config('services.meta.redirect'), 'state' => $state,
            'response_type' => 'code', 'config_id' => config('services.meta.wa_config_id'), 'override_default_response_type' => 'true',
        ])];
    }

    /**
     * From the OAuth callback: one number is connected right away; several are kept for 15 minutes
     * so the seller can pick one. Returns 'connected' or 'pick'.
     */
    public static function fromToken(User $user, string $token): string
    {
        $token = self::longLived($token);
        $numbers = self::numbers($token);
        if (! $numbers) {
            throw new RuntimeException('Tidak ada nomor WhatsApp Business yang diizinkan. Saat login, pilih akun WhatsApp Business dan nomornya.');
        }
        if (count($numbers) === 1) {
            self::save($user, $token, $numbers[0]);

            return 'connected';
        }
        Cache::put("wa:connect:{$user->id}", Crypt::encryptString(json_encode(['token' => $token, 'numbers' => $numbers])), now()->addMinutes(15));

        return 'pick';
    }

    public function pending(Request $request)
    {
        $p = self::pendingFor($request->user());

        return ['numbers' => $p['numbers'] ?? []];
    }

    public function select(Request $request)
    {
        $id = $request->validate(['phone_number_id' => 'required|string|max:40'])['phone_number_id'];
        $p = self::pendingFor($request->user());
        abort_unless($p, 422, 'Sesi login WhatsApp sudah habis. Klik Masuk dengan Facebook lagi.');
        $number = collect($p['numbers'])->firstWhere('id', $id);
        abort_unless($number, 422, 'Nomor tidak ditemukan.');
        $i = self::save($request->user(), $p['token'], $number);
        Cache::forget("wa:connect:{$request->user()->id}");

        return ['phone' => $i->settings['display_phone'] ?? null];
    }

    private static function pendingFor(User $user): ?array
    {
        $raw = Cache::get("wa:connect:{$user->id}");

        return $raw ? json_decode(Crypt::decryptString($raw), true) : null;
    }

    /** 60-day token for user logins; system-user tokens from a business login are kept as they are. */
    private static function longLived(string $token): string
    {
        $res = Http::timeout(20)->get(MetaAds::graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token', 'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'), 'fb_exchange_token' => $token,
        ]);

        return $res->successful() && $res->json('access_token') ? $res->json('access_token') : $token;
    }

    /** Every number in the WhatsApp Business Accounts this login was granted. */
    public static function numbers(string $token): array
    {
        $app = config('services.meta.app_id').'|'.config('services.meta.app_secret');
        $scopes = Http::timeout(20)->get(MetaAds::graph('debug_token'), ['input_token' => $token, 'access_token' => $app])->json('data.granular_scopes') ?? [];
        $wabas = collect($scopes)->whereIn('scope', ['whatsapp_business_management', 'whatsapp_business_messaging'])->pluck('target_ids')->flatten()->filter()->unique()->values();

        $out = [];
        foreach ($wabas as $waba) {
            $res = Http::timeout(20)->get(MetaAds::graph("{$waba}/phone_numbers"), [
                'access_token' => $token, 'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status',
            ]);
            foreach ($res->json('data') ?? [] as $n) {
                $out[] = [
                    'id' => (string) $n['id'], 'waba_id' => (string) $waba, 'display_phone' => $n['display_phone_number'] ?? null,
                    'verified_name' => $n['verified_name'] ?? null, 'quality' => $n['quality_rating'] ?? null,
                ];
            }
        }

        return $out;
    }

    private static function save(User $user, string $token, array $number): UserIntegration
    {
        $i = UserIntegration::firstOrNew(['user_id' => $user->id, 'provider' => 'whatsapp_cloud']);
        $i->user_id = $user->id;
        $i->webhook_token ??= Str::random(40);
        $expires = Http::timeout(20)->get(MetaAds::graph('debug_token'), ['input_token' => $token, 'access_token' => config('services.meta.app_id').'|'.config('services.meta.app_secret')])->json('data.expires_at');
        $i->fill([
            'credentials' => array_merge($i->credentials ?? [], ['access_token' => $token]),
            'settings' => array_merge($i->settings ?? [], [
                'phone_number_id' => $number['id'], 'waba_id' => $number['waba_id'], 'display_phone' => $number['display_phone'],
                'verified_name' => $number['verified_name'], 'quality' => $number['quality'], 'connected_via' => 'login',
                'token_expires_at' => $expires ? now()->setTimestamp((int) $expires)->toIso8601String() : null,
            ]),
            'is_production' => true, 'status' => 'active', 'last_error' => null, 'verified_at' => now(),
        ])->save();
        try {
            WhatsAppCloud::for($i)->subscribeApp();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('WhatsApp subscribe after login: '.$e->getMessage());
        }
        Cache::forget("wa:limits:{$i->id}");
        ActivityLog::record($user, 'integration.whatsapp_cloud', 'WhatsApp terhubung lewat login Facebook: '.($number['display_phone'] ?? $number['id']));

        return $i;
    }
}
