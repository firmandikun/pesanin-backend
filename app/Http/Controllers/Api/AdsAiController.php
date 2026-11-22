<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AdsAi;
use App\Support\Ai\Gemini;
use App\Support\Dates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Iklan Shopee → Analisa AI Iklan (seller's own Gemini API key). */
class AdsAiController extends Controller
{
    public function index(Request $request)
    {
        [$shop, $from, $to] = $this->scope($request);

        return ['gemini_connected' => (bool) Gemini::key($request->user()), 'analysis' => Cache::get($this->key($request, $shop, $from, $to))];
    }

    public function analyze(Request $request)
    {
        $request->validate(['question' => 'nullable|string|max:500']);
        $user = $request->user();
        $apiKey = Gemini::key($user);
        abort_unless($apiKey, 422, 'API Key Gemini belum terhubung. Atur di Pengaturan → Integrasi.');
        [$shop, $from, $to] = $this->scope($request);
        $snap = AdsAi::snapshot($user, $shop, $from, $to);
        abort_unless($snap['campaigns'], 422, 'Belum ada iklan Shopee yang berjalan di periode ini untuk dianalisa.');
        @set_time_limit(180);
        try {
            $analysis = AdsAi::analyze($apiKey, $snap, $request->input('question')) + ['totals' => $snap['totals'], 'period' => $snap['period']];
        } catch (Throwable $e) {
            abort(422, 'Analisa AI gagal: '.$e->getMessage());
        }
        Cache::put($this->key($request, $shop, $from, $to), $analysis, now()->addDays(7));

        return ['analysis' => $analysis];
    }

    /** @return array{0: ?int, 1: string, 2: string} shop id (or all), from, to */
    private function scope(Request $request): array
    {
        $shop = $request->integer('shop_id') ?: null;
        abort_if($shop && ! $request->user()->marketplaceShops()->whereKey($shop)->exists(), 404);
        [$from, $to] = Dates::range($request->input('from'), $request->input('to'), 30);

        // Dates::range gives UTC instants; the ad stats are per WIB day.
        return [$shop, $from->setTimezone(Dates::TZ)->toDateString(), $to->setTimezone(Dates::TZ)->toDateString()];
    }

    private function key(Request $request, ?int $shop, string $from, string $to): string
    {
        return "ads_ai:{$request->user()->id}:".($shop ?? 'all').":{$from}:{$to}";
    }
}
