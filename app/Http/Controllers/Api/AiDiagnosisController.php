<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Ai\Gemini;
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

        return [
            'gemini_connected' => (bool) Gemini::key($user),
            'snapshot' => AiBusinessDiagnosis::snapshot($user, $from, $to),
            'diagnosis' => Cache::get(self::key($user->id, $from, $to)),
            'history' => Cache::get("ai_diag_hist:{$user->id}", []),
        ];
    }

    public function analyze(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'question' => 'nullable|string|max:500']);
        $apiKey = Gemini::key($user);
        abort_unless($apiKey, 422, 'API Key Gemini belum terhubung. Atur di Pengaturan → Integrasi.');

        [$from, $to] = Dates::range($data['from'] ?? null, $data['to'] ?? null, 30);
        $snapshot = AiBusinessDiagnosis::snapshot($user, $from, $to);
        @set_time_limit(180);
        try {
            $diagnosis = AiBusinessDiagnosis::diagnose($apiKey, $snapshot, $data['question'] ?? null);
        } catch (Throwable $e) {
            abort(422, 'Diagnosa AI gagal: '.$e->getMessage());
        }
        Cache::put(self::key($user->id, $from, $to), $diagnosis, now()->addDays(7));

        // Score history: shows whether the business is getting healthier between diagnoses.
        $hist = Cache::get("ai_diag_hist:{$user->id}", []);
        $hist[] = ['score' => $diagnosis['health_score'], 'label' => $diagnosis['health_label'] ?? null, 'at' => $diagnosis['generated_at'],
            'from' => $snapshot['period']['from'], 'to' => $snapshot['period']['to'], 'net_profit' => $snapshot['financials']['net_profit']];
        $hist = array_slice($hist, -12);
        Cache::forever("ai_diag_hist:{$user->id}", $hist);

        return ['snapshot' => $snapshot, 'diagnosis' => $diagnosis, 'history' => $hist];
    }

    private static function key(int $userId, $from, $to): string
    {
        return "ai_diag:{$userId}:".$from->copy()->setTimezone(Dates::TZ)->toDateString().':'.$to->copy()->setTimezone(Dates::TZ)->toDateString();
    }
}
