<?php

namespace App\Support\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The seller's own Google Gemini API key (Pengaturan → Integrasi), used by the AI pages (Diagnosa Bisnis, Analisa
 * Iklan). Asks for JSON, tries the newest available models in turn, and validates the answer has the fields we need.
 */
class Gemini
{
    public static function key(User $user): ?string
    {
        $i = $user->integrations()->where('provider', 'gemini')->where('status', 'active')->first();

        return $i?->key('api_key') ?: null;
    }

    /**
     * Prompt → decoded JSON. $required = top-level keys the answer must have.
     * @return array the answer + ['model' => …, 'generated_at' => …]
     */
    public static function json(string $apiKey, string $prompt, array $required, float $temperature = 0.2): array
    {
        $lastErr = 'Gagal menghubungi Gemini AI.';
        foreach (self::models($apiKey) as $model) {
            try {
                $res = Http::timeout(90)->post(
                    "https://generativelanguage.googleapis.com/v1beta/{$model}:generateContent?key=".urlencode($apiKey),
                    ['contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['temperature' => $temperature, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 16384]],
                );
            } catch (Throwable $e) {
                $lastErr = 'Gemini tidak menjawab ('.$e->getMessage().').';

                continue;
            }
            if (! $res->successful()) {
                $lastErr = $res->json('error.message') ?? "HTTP {$res->status()}";
                // A wrong / blocked key fails on every model: stop early.
                if (in_array($res->status(), [400, 401, 403], true) && str_contains(strtolower($lastErr), 'api key')) {
                    break;
                }

                continue;
            }
            $text = collect($res->json('candidates.0.content.parts', []))->pluck('text')->implode('');
            if ($text === '') {
                $lastErr = 'Respons Gemini kosong ('.($res->json('candidates.0.finishReason') ?? 'tanpa alasan').').';

                continue;
            }
            $decoded = json_decode((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
            if (is_array($decoded) && ! array_diff($required, array_keys($decoded))) {
                return $decoded + ['model' => str_replace('models/', '', $model), 'generated_at' => now()->toIso8601String()];
            }
            $lastErr = 'Format jawaban AI tidak sesuai, coba lagi.';
        }

        throw new RuntimeException($lastErr);
    }

    /** generateContent models of this key, newest flash / pro first (cached 6 hours). */
    public static function models(string $apiKey): array
    {
        $fallback = ['models/gemini-2.5-flash', 'models/gemini-2.5-pro', 'models/gemini-2.0-flash'];

        return Cache::remember('gemini_models:'.md5($apiKey), now()->addHours(6), function () use ($apiKey, $fallback) {
            try {
                $res = Http::timeout(10)->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $apiKey]);
                if (! $res->successful()) {
                    return $fallback;
                }
                $list = collect($res->json('models', []))
                    ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
                    ->pluck('name')
                    ->filter(fn ($n) => str_contains($n, 'gemini-') && ! preg_match('/embedding|tts|image|audio|live|thinking-exp|learnlm|gemma/', $n))
                    ->sortByDesc(function ($n) {
                        preg_match('/gemini-(\d+(?:\.\d+)?)/', $n, $m);
                        $score = (float) ($m[1] ?? 0) * 100;
                        $score += str_contains($n, 'flash') ? 20 : (str_contains($n, 'pro') ? 10 : 0);
                        $score -= str_contains($n, 'lite') ? 15 : 0;
                        $score -= str_contains($n, 'preview') || str_contains($n, 'exp') ? 5 : 0;

                        return $score;
                    })->values()->take(4)->all();

                return $list ?: $fallback;
            } catch (Throwable) {
                return $fallback;
            }
        });
    }
}
