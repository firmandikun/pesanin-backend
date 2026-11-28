<?php

namespace App\Support\MetaSpy;

use Illuminate\Support\Carbon;

/**
 * Meta Ad Library GraphQL → Pesanin rows. Meta's payloads change shape often and some responses are several JSON
 * documents (or prefixed with "for (;;);"), so ads are found by walking every object that has an `ad_archive_id`
 * instead of relying on one fixed path. Mirrored in Go: services/internal/metaspy.
 */
class AdParser
{
    public const PLATFORMS = ['FACEBOOK' => 'facebook', 'INSTAGRAM' => 'instagram', 'AUDIENCE_NETWORK' => 'audience_network', 'MESSENGER' => 'messenger', 'THREADS' => 'threads'];

    /** Every ad node in a raw GraphQL text (one or more JSON documents). @return array<int, array> */
    public static function nodesFromRaw(string $raw): array
    {
        $raw = preg_replace('/^\s*for\s*\(\s*;\s*;\s*\)\s*;/', '', $raw) ?? '';
        $out = [];
        foreach (preg_split('/\r?\n(?=\s*[\[{])/', $raw) ?: [] as $chunk) {
            $doc = json_decode(trim($chunk), true);
            if (is_array($doc)) {
                self::walk($doc, $out);
            }
        }

        return $out;
    }

    /** Ad nodes inside already decoded data (what the extension sends). */
    public static function nodes(mixed $data): array
    {
        $out = [];
        if (is_array($data)) {
            self::walk($data, $out);
        }

        return $out;
    }

    private static function walk(array $node, array &$out, int $depth = 0): void
    {
        if ($depth > 40) {
            return;
        }
        if (isset($node['ad_archive_id']) && (is_string($node['ad_archive_id']) || is_int($node['ad_archive_id']))) {
            $out[] = $node;

            return; // an ad's own snapshot never holds other ads
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                self::walk($v, $out, $depth + 1);
            }
        }
    }

    /** One ad node → the meta_spy_ads columns (without user / score). Returns null when it has no usable id. */
    public static function normalize(array $n): ?array
    {
        $id = preg_replace('/\D/', '', (string) ($n['ad_archive_id'] ?? ''));
        if ($id === '' || strlen($id) > 40) {
            return null;
        }
        $s = is_array($n['snapshot'] ?? null) ? $n['snapshot'] : [];
        $text = fn ($v) => is_array($v) ? (string) ($v['text'] ?? '') : (string) ($v ?? '');
        $clip = fn (?string $v, int $max) => $v === null || $v === '' ? null : mb_substr(trim($v), 0, $max);

        $cards = array_values(array_filter($s['cards'] ?? [], 'is_array'));
        $videos = array_values(array_filter($s['videos'] ?? [], 'is_array'));
        $images = array_values(array_filter($s['images'] ?? [], 'is_array'));
        $media = [];
        foreach ($videos as $v) {
            if ($u = $v['video_hd_url'] ?? $v['video_sd_url'] ?? null) {
                $media[] = ['type' => 'video', 'url' => $u, 'preview' => $v['video_preview_image_url'] ?? null];
            }
        }
        foreach ($images as $i) {
            if ($u = $i['original_image_url'] ?? $i['resized_image_url'] ?? null) {
                $media[] = ['type' => 'image', 'url' => $u, 'preview' => $i['resized_image_url'] ?? $u];
            }
        }
        foreach ($cards as $c) {
            if ($u = $c['video_hd_url'] ?? $c['video_sd_url'] ?? null) {
                $media[] = ['type' => 'video', 'url' => $u, 'preview' => $c['video_preview_image_url'] ?? null];
            } elseif ($u = $c['original_image_url'] ?? $c['resized_image_url'] ?? null) {
                $media[] = ['type' => 'image', 'url' => $u, 'preview' => $c['resized_image_url'] ?? $u];
            }
        }
        $format = strtoupper((string) ($s['display_format'] ?? ''));
        $mediaType = match (true) {
            $format === 'CAROUSEL' || $format === 'DCO' || count($cards) > 1 => 'carousel',
            $format === 'VIDEO' || (bool) $videos || (bool) collect($media)->firstWhere('type', 'video') => 'video',
            (bool) $media || $format === 'IMAGE' => 'image',
            default => null,
        };
        $first = $cards[0] ?? [];
        $body = $text($s['body'] ?? null) ?: $text($first['body'] ?? null);
        $title = (string) ($s['title'] ?? '') ?: (string) ($first['title'] ?? '');
        $link = (string) ($s['link_url'] ?? '') ?: (string) ($first['link_url'] ?? '');
        $ctaType = (string) ($s['cta_type'] ?? '') ?: (string) ($first['cta_type'] ?? '');

        $platforms = collect((array) ($n['publisher_platform'] ?? $n['publisher_platforms'] ?? []))
            ->map(fn ($p) => self::PLATFORMS[strtoupper((string) $p)] ?? strtolower((string) $p))->filter()->unique()->values()->all();

        return [
            'ad_archive_id' => $id,
            'page_id' => $clip((string) ($n['page_id'] ?? $s['page_id'] ?? ''), 40),
            'page_name' => $clip((string) ($n['page_name'] ?? $s['page_name'] ?? ''), 191),
            'page_picture_url' => $clip((string) ($s['page_profile_picture_url'] ?? ''), 1000),
            'collation_count' => max(1, (int) ($n['collation_count'] ?? 1)),
            'start_date' => self::date($n['start_date'] ?? null),
            'end_date' => self::date($n['end_date'] ?? null),
            'is_active' => (bool) ($n['is_active'] ?? true),
            'platforms' => $platforms,
            'title' => $clip($title, 500),
            'body' => $clip($body, 5000),
            'cta_text' => $clip((string) ($s['cta_text'] ?? $first['cta_text'] ?? ''), 100),
            'cta_type' => $clip($ctaType, 50),
            'link_url' => $clip($link, 1000),
            'link_type' => self::linkType($link, $ctaType),
            'media_type' => $mediaType,
            'thumbnail_url' => $clip((string) (collect($media)->first()['preview'] ?? collect($media)->first()['url'] ?? ''), 1000),
            'media_urls' => array_slice($media, 0, 10),
        ];
    }

    private static function date(mixed $v): ?string
    {
        if ($v === null || $v === '' || $v === 0) {
            return null;
        }
        try {
            return (is_numeric($v) ? Carbon::createFromTimestamp((int) $v, 'Asia/Jakarta') : Carbon::parse((string) $v))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function linkType(string $url, string $ctaType = ''): ?string
    {
        $u = strtolower($url);
        $cta = strtoupper($ctaType);

        return match (true) {
            str_contains($u, 'wa.me') || str_contains($u, 'whatsapp') || str_contains($cta, 'WHATSAPP') => 'whatsapp',
            str_contains($u, 'm.me') || str_contains($u, 'messenger') || $cta === 'MESSAGE_PAGE' => 'messenger',
            str_contains($u, 'instagram.com') || str_contains($cta, 'INSTAGRAM') => 'instagram',
            $u !== '' => 'website',
            default => null,
        };
    }

    /**
     * WINNING: duplicated ≥ 5 times and on air ≥ 7 days. SCALE: duplicated ≥ 3 or on air ≥ 4 days. TEST: the rest.
     * Duplicates (collation_count) = how many near-identical copies the advertiser runs, a strong "it sells" signal.
     */
    public static function score(int $duplicates, int $daysRunning): string
    {
        return match (true) {
            $duplicates >= 5 && $daysRunning >= 7 => 'WINNING',
            $duplicates >= 3 || $daysRunning >= 4 => 'SCALE',
            default => 'TEST',
        };
    }

    public static function daysRunning(?string $start, ?string $end, bool $active, ?Carbon $now = null): int
    {
        if (! $start) {
            return 0;
        }
        $to = $active || ! $end ? ($now ?? now())->copy()->setTimezone('Asia/Jakarta')->startOfDay() : Carbon::parse($end, 'Asia/Jakarta');

        return max(0, (int) Carbon::parse($start, 'Asia/Jakarta')->diffInDays($to));
    }
}
