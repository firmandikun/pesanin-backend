<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackingPixel;
use App\Support\Meta\ConversionsApi;
use Illuminate\Http\Request;

/** Pengaturan → Tracking: Meta Conversions API connection test. */
class TrackingController extends Controller
{
    public function capiTest(Request $request, int $id)
    {
        $pixel = TrackingPixel::where('user_id', $request->user()->id)->where('platform', 'facebook')->findOrFail($id);
        abort_unless($pixel->has_capi_token, 422, 'Isi Access Token Conversions API dulu.');
        $code = $request->validate(['test_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+$/']])['test_code'] ?? $pixel->capi_test_code;
        $error = ConversionsApi::test($pixel, $code, $request->ip(), $request->userAgent());
        abort_if($error, 422, "Meta menolak: {$error}");

        return ['ok' => true, 'mode' => $code ? 'event' : 'token', 'pixel' => $pixel->fresh()];
    }
}
