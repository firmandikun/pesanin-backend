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
        if (count($numbers) === 1) {
            self::save($user, $token, $numbers[0]);

            return 'connected';
        }
        Cache::put("wa:connect:{$user->id}", Crypt::encryptString(json_encode(['token' => $token, 'numbers' => $numbers])), now()->addMinutes(15));

        return 'pick';
    }

    /**
     * Pengaturan → WhatsApp: everything the setup checklist needs in one call — the connected number, how it was
     * connected, and whether customer messages have reached Pesanin yet (proof the webhook works).
     */
    public function status(Request $request)
    {
        $i = WhatsAppChatController::integration($request->user());
        $lastIn = $i ? \App\Models\WaMessage::where('user_id', $request->user()->id)->where('direction', 'in')->latest('sent_at')->value('sent_at') : null;
        $s = $i?->settings ?? [];

        return [
            'connected' => (bool) $i, 'phone' => $s['display_phone'] ?? null, 'verified_name' => $s['verified_name'] ?? null, 'quality' => $s['quality'] ?? null,
            'connected_via' => $s['connected_via'] ?? ($i ? 'token' : null), 'token_expires_at' => $s['token_expires_at'] ?? null,
            'last_error' => $i?->last_error, 'last_inbound_at' => $lastIn ? \Carbon\Carbon::parse($lastIn)->toIso8601String() : null,
            'pending_numbers' => count(self::pendingFor($request->user())['numbers'] ?? []),
        ];
    }

    /**
     * "Kirim pesan tes": Meta's built-in hello_world template (every new WhatsApp Business Account has it) to the
     * seller's own phone, so they see the connection work end to end. Meta's errors are explained in plain words.
     */
    public function test(Request $request)
    {
        $to = \App\Models\Customer::normalizePhone($request->validate(['to' => 'required|string|max:30'])['to']);
        abort_unless($to && strlen($to) >= 10, 422, 'Nomor tujuan tidak valid.');
        $wa = WhatsAppCloud::for(WhatsAppChatController::integration($request->user()));
        abort_unless($wa, 422, 'WhatsApp belum terhubung.');
        try {
            $wa->sendTemplate($to, 'hello_world', 'en_US');
        } catch (\Throwable $e) {
            abort(422, self::explain($e->getMessage(), true, (int) $e->getCode()));
        }

        return ['ok' => true];
    }

    /**
     * Meta error → what the seller should do. $code is Meta's error code (WhatsAppCloud passes it as the exception
     * code); unknown errors keep their text, with "Meta menolak:" when $prefix.
     */
    public static function explain(string $error, bool $prefix = true, int $code = 0): string
    {
        $e = strtolower($error);

        return match (true) {
            $code === 132001 || str_contains($e, 'template name does not exist') => 'Template tes "hello_world" tidak ada di akun WhatsApp Business-mu. Koneksi tetap bisa dipakai; buat template sendiri di menu Broadcast → Template.',
            $code === 133010 || str_contains($e, 'not registered') => 'Nomor belum terdaftar di WhatsApp Cloud API. Buka WhatsApp Manager → Nomor telepon, selesaikan pendaftaran/verifikasi nomor, lalu coba lagi.',
            $code === 131042 || str_contains($e, 'payment') => 'Metode pembayaran Meta belum diatur. Tambahkan kartu di Meta Business → Penagihan WhatsApp agar bisa mengirim template.',
            $code === 190 || str_contains($e, 'session has expired') => 'Login Facebook sudah kedaluwarsa. Klik "Masuk dengan Facebook" lagi untuk memperbarui.',
            in_array($code, [10, 200], true) || str_contains($e, 'permission') => 'Izin akun belum lengkap. Login ulang dan centang akun WhatsApp Business serta nomornya.',
            str_contains($e, 'user denied') || str_contains($e, 'access_denied') || str_contains($e, 'permissions error') => 'Login dibatalkan. Klik "Masuk dengan Facebook" lagi dan selesaikan semua langkahnya.',
            default => ($prefix && ! str_starts_with($error, 'WhatsApp') && ! $code ? 'Meta menolak: ' : '').mb_substr($error, 0, 250),
        };
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

    /**
     * Every number in the WhatsApp Business Accounts this login was granted. The accounts come from the token's
     * granular scopes; when Meta leaves those empty (access to "all" accounts), from the businesses the user manages.
     * Throws a seller-readable error saying what is missing: the permission, the account, or a number in it.
     */
    public static function numbers(string $token): array
    {
        $app = config('services.meta.app_id').'|'.config('services.meta.app_secret');
        $scopes = collect(Http::timeout(20)->get(MetaAds::graph('debug_token'), ['input_token' => $token, 'access_token' => $app])->json('data.granular_scopes') ?? []);
        $waScopes = $scopes->whereIn('scope', ['whatsapp_business_management', 'whatsapp_business_messaging']);
        if ($waScopes->isEmpty()) {
            throw new RuntimeException('Izin WhatsApp belum diberikan. Klik "Masuk dengan Facebook" lagi dan di langkah izin centang semua izin WhatsApp (kelola & kirim pesan).');
        }
        $wabas = $waScopes->pluck('target_ids')->flatten()->filter()->map(fn ($id) => (string) $id)->unique()->values();
        if ($wabas->isEmpty()) {
            $wabas = self::wabasFromBusinesses($token);
        }
        if ($wabas->isEmpty()) {
            throw new RuntimeException('Belum ada akun WhatsApp Business yang dipilih. Saat login, pilih (atau buat) akun WhatsApp Business di langkah "Pilih akun WhatsApp Business", lalu centang akun itu di langkah izin.');
        }

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
        if (! $out) {
            throw new RuntimeException('Akun WhatsApp Business sudah terhubung, tapi belum ada nomor telepon di dalamnya. Login lagi lalu di langkah "Tambah nomor telepon" masukkan nomor dan kode verifikasinya, atau tambahkan nomor di WhatsApp Manager (business.facebook.com/wa/manage/phone-numbers) lalu login ulang.');
        }

        return $out;
    }

    /** WhatsApp Business Accounts of the businesses this user manages (owned or shared with them). */
    private static function wabasFromBusinesses(string $token): \Illuminate\Support\Collection
    {
        $res = Http::timeout(20)->get(MetaAds::graph('me/businesses'), [
            'access_token' => $token, 'fields' => 'owned_whatsapp_business_accounts{id},client_whatsapp_business_accounts{id}', 'limit' => 50,
        ]);

        return collect($res->json('data') ?? [])
            ->flatMap(fn ($b) => array_merge($b['owned_whatsapp_business_accounts']['data'] ?? [], $b['client_whatsapp_business_accounts']['data'] ?? []))
            ->pluck('id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
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
