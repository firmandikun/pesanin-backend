<?php

namespace App\Http\Controllers;

use App\Models\MetaAdInsight;
use App\Models\UserIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Public pages Meta (and other platforms) require before an app can go live:
 * privacy policy, terms of service, and user data deletion (instructions + Meta's signed callback).
 */
class LegalController extends Controller
{
    public function privacy()
    {
        return view('legal.privacy', ['updated' => '8 November 2026']);
    }

    public function terms()
    {
        return view('legal.terms', ['updated' => '8 November 2026']);
    }

    /** Instructions page; with ?code= it also shows the status of a deletion request. */
    public function deletion(Request $request)
    {
        $req = $request->query('code') ? DB::table('data_deletion_requests')->where('code', $request->query('code'))->first() : null;

        return view('legal.deletion', ['req' => $req, 'code' => $request->query('code')]);
    }

    /**
     * Meta "Data Deletion Request Callback": POST signed_request when a person removes the app
     * from their Facebook settings. Deletes every Meta integration (ads + chat) for that app-scoped user id
     * along with synced ad insights, then answers with a status URL and confirmation code.
     */
    public function metaDeletion(Request $request)
    {
        $data = self::parseSignedRequest((string) $request->input('signed_request'), (string) config('services.meta.app_secret'));
        abort_unless($data && ! empty($data['user_id']), 400, 'signed_request tidak valid.');

        $fbId = (string) $data['user_id'];
        $integrations = UserIntegration::whereIn('provider', ['meta_ads', 'meta_chat'])->get()
            ->filter(fn ($i) => (string) ($i->settings['fb_user_id'] ?? '') === $fbId);
        foreach ($integrations->where('provider', 'meta_ads') as $i) {
            MetaAdInsight::where('user_id', $i->user_id)->delete();
        }
        $count = $integrations->count();
        UserIntegration::whereKey($integrations->modelKeys())->delete();

        $code = Str::lower(Str::random(16));
        DB::table('data_deletion_requests')->insert([
            'code' => $code, 'source' => 'meta', 'external_user_id' => $fbId,
            'deleted_integrations' => $count, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Log::info('Meta data deletion', ['fb_user_id' => $fbId, 'integrations' => $count, 'code' => $code]);

        return response()->json(['url' => url('/data-deletion').'?code='.$code, 'confirmation_code' => $code]);
    }

    public static function parseSignedRequest(string $signed, string $secret): ?array
    {
        if (! $secret || ! str_contains($signed, '.')) {
            return null;
        }
        [$sig, $payload] = explode('.', $signed, 2);
        $decode = fn ($s) => base64_decode(strtr($s, '-_', '+/'));
        $expected = hash_hmac('sha256', $payload, $secret, true);
        if (! hash_equals($expected, (string) $decode($sig))) {
            return null;
        }
        $data = json_decode((string) $decode($payload), true);

        return is_array($data) && strtoupper($data['algorithm'] ?? '') === 'HMAC-SHA256' ? $data : null;
    }
}
