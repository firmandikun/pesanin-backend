<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Ai\AiJob;
use App\Support\Ai\Gemini;
use App\Support\AiBusinessDiagnosis;
use App\Support\Dates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Laporan Bisnis → Diagnosa Bisnis AI (powered by seller's Google Gemini API key). */
class AiDiagnosisController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        [$from, $to] = Dates::range($request->query('from'), $request->query('to'), 30);
        $key = self::key($user->id, $from, $to);

        return [
            'gemini_connected' => (bool) Gemini::key($user),
            'snapshot' => AiBusinessDiagnosis::snapshot($user, $from, $to),
            'diagnosis' => Cache::get($key),
            'job' => AiJob::status($key),
            'history' => Cache::get("ai_diag_hist:{$user->id}", []),
        ];
    }

    /** Start the diagnosis in the background (Gemini takes longer than the web server waits); the page polls index. */
    public function analyze(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'question' => 'nullable|string|max:500']);
        $apiKey = Gemini::key($user);
        abort_unless($apiKey, 422, 'API Key Gemini belum terhubung. Atur di Pengaturan → Integrasi.');
        [$from, $to] = Dates::range($data['from'] ?? null, $data['to'] ?? null, 30);
        $key = self::key($user->id, $from, $to);
        if ((AiJob::status($key)['status'] ?? null) === 'running') {
            return response()->json(['job' => AiJob::status($key)], 202);
        }
        $snapshot = AiBusinessDiagnosis::snapshot($user, $from, $to);
        $question = $data['question'] ?? null;
        $userId = $user->id;
        $job = AiJob::start($key, fn () => AiBusinessDiagnosis::diagnose($apiKey, $snapshot, $question), function ($diagnosis) use ($userId, $snapshot) {
            // Score history: shows whether the business is getting healthier between diagnoses.
            $hist = Cache::get("ai_diag_hist:{$userId}", []);
            $hist[] = ['score' => $diagnosis['health_score'], 'label' => $diagnosis['health_label'] ?? null, 'at' => $diagnosis['generated_at'],
                'from' => $snapshot['period']['from'], 'to' => $snapshot['period']['to'], 'net_profit' => $snapshot['financials']['net_profit']];
            Cache::forever("ai_diag_hist:{$userId}", array_slice($hist, -12));
        });

        return response()->json(['job' => $job, 'snapshot' => $snapshot], 202);
    }

    private static function key(int $userId, $from, $to): string
    {
        return "ai_diag:{$userId}:".$from->copy()->setTimezone(Dates::TZ)->toDateString().':'.$to->copy()->setTimezone(Dates::TZ)->toDateString();
    }
}
