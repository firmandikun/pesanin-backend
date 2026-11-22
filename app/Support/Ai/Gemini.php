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
        $errors = []; // model => message
        $started = microtime(true);
        foreach (self::models($apiKey) as $model) {
            // Stay inside the background job's time (each model may take up to 90 s).
            if (microtime(true) - $started > 200) {
                break;
            }
            $name = str_replace('models/', '', $model);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $res = Http::timeout(90)->post(
                        "https://generativelanguage.googleapis.com/v1beta/{$model}:generateContent?key=".urlencode($apiKey),
                        ['contents' => [['parts' => [['text' => $prompt]]]],
                            'generationConfig' => ['temperature' => $temperature, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 16384]],
                    );
                } catch (Throwable $e) {
                    $errors[$name] = 'tidak menjawab ('.$e->getMessage().')';

                    continue 2;
                }
                if ($res->status() === 429) {
                    // Per-minute limit: Google says how long to wait; wait once if it is short, else try the next model.
                    $wait = self::retryDelay($res->json('error.details', []));
                    $errors[$name] = (string) ($res->json('error.message') ?? 'HTTP 429');
                    if ($attempt === 0 && $wait !== null && $wait <= 25 && microtime(true) - $started + $wait < 200) {
                        sleep($wait + 1);

                        continue;
                    }

                    continue 2;
                }
                if (! $res->successful()) {
                    $errors[$name] = (string) ($res->json('error.message') ?? "HTTP {$res->status()}");
                    // A wrong / blocked key fails on every model: stop early.
                    if (in_array($res->status(), [400, 401, 403], true) && str_contains(strtolower($errors[$name]), 'api key')) {
                        break 2;
                    }

                    continue 2;
                }
                $text = collect($res->json('candidates.0.content.parts', []))->pluck('text')->implode('');
                if ($text === '') {
                    $errors[$name] = 'jawaban kosong ('.($res->json('candidates.0.finishReason') ?? 'tanpa alasan').')';

                    continue 2;
                }
                $decoded = json_decode((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
                if (is_array($decoded) && ! array_diff($required, array_keys($decoded))) {
                    return $decoded + ['model' => $name, 'generated_at' => now()->toIso8601String()];
                }
                $errors[$name] = 'format jawaban tidak sesuai';

                continue 2;
            }
        }

        throw new RuntimeException(self::summarize($errors));
    }

    /** Seconds Google asks to wait (RetryInfo.retryDelay, e.g. "34s"), if any. */
    private static function retryDelay(array $details): ?int
    {
        foreach ($details as $d) {
            if (isset($d['retryDelay']) && preg_match('/^(\d+)(?:\.\d+)?s$/', (string) $d['retryDelay'], $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** One clear message from the per-model errors (which model ran out of which quota). */
    public static function summarize(array $errors): string
    {
        if (! $errors) {
            return 'Gagal menghubungi Gemini AI.';
        }
        $quota = array_filter($errors, fn ($e) => preg_match('/quota|exhausted|rate limit|429/i', $e));
        if (count($quota) === count($errors)) {
            $parts = collect($quota)->map(function ($e, $model) {
                $why = preg_match('/limit:\s*0\b/i', $e) ? 'tidak tersedia di paket gratis'
                    : (preg_match('/per ?day|PerDay|daily/i', $e) ? 'batas harian habis' : 'batas per menit tercapai');

                return "{$model} ({$why})";
            })->implode(', ');
            $daily = collect($quota)->contains(fn ($e) => preg_match('/per ?day|PerDay|daily/i', $e));

            return "Kuota Gemini terpakai habis untuk model: {$parts}. "
                .($daily ? 'Batas harian reset besok (sekitar 14.00/15.00 WIB), atau aktifkan billing di Google AI Studio.' : 'Tunggu ±1 menit lalu coba lagi.')
                .' Pesan Google: "'.mb_strimwidth((string) reset($quota), 0, 220, '…').'"';
        }
        // Not (only) quota: the most telling non-quota error.
        $other = array_diff_key($errors, $quota);
        $model = array_key_first($other);

        return self::friendly((string) $other[$model]).($model ? " (model {$model})" : '');
    }

    /** Google's usual errors in plain Indonesian (the original kept in brackets). */
    public static function friendly(string $err): string
    {
        $e = strtolower($err);

        return match (true) {
            str_contains($e, 'api key not valid') || str_contains($e, 'api_key_invalid') => 'API Key Gemini tidak valid. Periksa / ganti key di Pengaturan → Integrasi.',
            str_contains($e, 'quota') || str_contains($e, 'exhausted') || str_contains($e, 'rate limit') => 'Kuota API Gemini Anda habis untuk saat ini. Coba lagi beberapa menit lagi, atau aktifkan billing di Google AI Studio.',
            str_contains($e, 'overloaded') || str_contains($e, 'unavailable') || str_contains($e, 'high demand') => 'Server Gemini sedang sibuk. Coba lagi sebentar lagi.',
            str_contains($e, 'permission') || str_contains($e, 'not enabled') => 'API Key Gemini tidak punya izin untuk model ini. Buat key baru di Google AI Studio.',
            str_contains($e, 'location') && str_contains($e, 'not supported') => 'Gemini API belum tersedia untuk lokasi server ini.',
            default => $err,
        };
    }

    /** generateContent models of this key in the order to try (cached 6 hours). */
    public static function models(string $apiKey): array
    {
        $fallback = ['models/gemini-2.5-flash', 'models/gemini-2.5-flash-lite', 'models/gemini-2.0-flash'];

        return Cache::remember('gemini_models:v2:'.md5($apiKey), now()->addHours(6), function () use ($apiKey, $fallback) {
            try {
                $res = Http::timeout(10)->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $apiKey]);
                if (! $res->successful()) {
                    return $fallback;
                }
                $list = collect($res->json('models', []))
                    ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
                    ->pluck('name')
                    ->filter(fn ($n) => str_contains($n, 'gemini-') && ! preg_match('/embedding|tts|image|audio|live|thinking-exp|learnlm|gemma/', $n))
                    // Stable Flash first (largest free quota), then Flash-Lite, Pro; preview / experimental models last
                    // (on the free tier they often have tiny or zero quota). Newer version first within a group.
                    ->sortByDesc(function ($n) {
                        preg_match('/gemini-(\d+(?:\.\d+)?)/', $n, $m);
                        $preview = (bool) preg_match('/preview|exp|latest/', $n);
                        $group = str_contains($n, 'flash-lite') ? 2 : (str_contains($n, 'flash') ? 3 : (str_contains($n, 'pro') ? 1 : 0));

                        return ($preview ? 0 : 10000) + $group * 1000 + (float) ($m[1] ?? 0) * 10;
                    })->values()->take(4)->all();

                return $list ?: $fallback;
            } catch (Throwable) {
                return $fallback;
            }
        });
    }
}
