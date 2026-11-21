<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AiBusinessDiagnosis;
use App\Support\Dates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Laporan Bisnis → Diagnosa Bisnis AI (powered by seller's Google Gemini API key). */
class AiDiagnosisController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        [$from, $to] = Dates::range($request->query('from'), $request->query('to'), 30);
        $snapshot = AiBusinessDiagnosis::snapshot($user, $from, $to);
        $gemini = $user->integrations()->where('provider', 'gemini')->where('status', 'active')->first();
        $cacheKey = "ai_diag:{$user->id}:{$from->toDateString()}:{$to->toDateString()}";

        return [
            'gemini_connected' => (bool) ($gemini && $gemini->key('api_key')),
            'snapshot' => $snapshot,
            'diagnosis' => Cache::get($cacheKey),
        ];
    }

    public function analyze(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'question' => 'nullable|string|max:500',
        ]);
        $gemini = $user->integrations()->where('provider', 'gemini')->where('status', 'active')->first();
        $apiKey = $gemini?->key('api_key');
        abort_unless($apiKey, 422, 'API Key Gemini belum terhubung. Atur di Pengaturan → Integrasi.');

        [$from, $to] = Dates::range($data['from'] ?? null, $data['to'] ?? null, 30);
        $snapshot = AiBusinessDiagnosis::snapshot($user, $from, $to);

        try {
            $diagnosis = AiBusinessDiagnosis::diagnose($apiKey, $snapshot, $data['question'] ?? null);
        } catch (Throwable $e) {
            abort(422, 'Diagnosa AI gagal: '.$e->getMessage());
        }

        $cacheKey = "ai_diag:{$user->id}:{$from->toDateString()}:{$to->toDateString()}";
        Cache::put($cacheKey, $diagnosis, now()->addHours(12));

        return [
            'snapshot' => $snapshot,
            'diagnosis' => $diagnosis,
        ];
    }
}
