<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AdsAi;
use App\Support\Ai\AiJob;
use App\Support\Ai\Gemini;
use App\Support\Dates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Iklan Shopee → Analisa AI Iklan (seller's own Gemini API key). */
class AdsAiController extends Controller
{
    public function index(Request $request)
    {
        [$shop, $from, $to] = $this->scope($request);
        $key = $this->key($request, $shop, $from, $to);

        return ['gemini_connected' => (bool) Gemini::key($request->user()), 'analysis' => Cache::get($key), 'job' => AiJob::status($key)];
    }

    /** Start the analysis in the background (Gemini takes longer than the web server waits); the page polls index. */
    public function analyze(Request $request)
    {
        $request->validate(['question' => 'nullable|string|max:500']);
        $user = $request->user();
        $apiKey = Gemini::key($user);
        abort_unless($apiKey, 422, 'API Key Gemini belum terhubung. Atur di Pengaturan → Integrasi.');
        [$shop, $from, $to] = $this->scope($request);
        $key = $this->key($request, $shop, $from, $to);
        if ((AiJob::status($key)['status'] ?? null) === 'running') {
            return response()->json(['job' => AiJob::status($key)], 202);
        }
        $snap = AdsAi::snapshot($user, $shop, $from, $to);
        abort_unless($snap['campaigns'], 422, 'Belum ada iklan Shopee yang berjalan di periode ini untuk dianalisa.');
        $question = $request->input('question');
        $job = AiJob::start($key, fn () => AdsAi::analyze($apiKey, $snap, $question) + ['totals' => $snap['totals'], 'period' => $snap['period']]);

        return response()->json(['job' => $job], 202);
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
