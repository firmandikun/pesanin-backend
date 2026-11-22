<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * An AI analysis runs after the HTTP response: Gemini takes 20–90 s, longer than the web server waits for a
 * request (nginx answers 504 after ~60 s). The page starts it, then polls the status until the result is cached.
 */
class AiJob
{
    /** A job still "running" after this long died with its PHP process. */
    public const STALE_SECONDS = 360;

    /** Start $work() after the response; its return value is cached under $key. $after receives the result. */
    public static function start(string $key, callable $work, ?callable $after = null): array
    {
        $state = ['status' => 'running', 'started_at' => now()->toIso8601String()];
        Cache::put("{$key}:job", $state, now()->addHour());
        dispatch(function () use ($key, $work, $after) {
            @set_time_limit(330);
            ignore_user_abort(true);
            try {
                $result = $work();
                Cache::put($key, $result, now()->addDays(7));
                if ($after) {
                    $after($result);
                }
                Cache::put("{$key}:job", ['status' => 'done', 'finished_at' => now()->toIso8601String()], now()->addHour());
            } catch (Throwable $e) {
                Cache::put("{$key}:job", ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)], now()->addHour());
            }
        })->afterResponse();

        return $state;
    }

    /** running | done | failed | null (never started / expired). */
    public static function status(string $key): ?array
    {
        $s = Cache::get("{$key}:job");
        if (($s['status'] ?? null) === 'running' && isset($s['started_at']) && now()->diffInSeconds($s['started_at'], true) > self::STALE_SECONDS) {
            return ['status' => 'failed', 'error' => 'Analisa terlalu lama dan dihentikan server. Coba lagi.'];
        }

        return $s;
    }
}
