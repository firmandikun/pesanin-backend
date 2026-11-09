<?php

namespace App\Support;

/**
 * Where a web-checkout buyer came from, read from the UTM tags on the ad/link.
 * Facebook/TikTok are traffic sources, not sales channels: the sale still happens on Pesanin's checkout.
 */
class Traffic
{
    public const SOURCES = [
        'meta' => ['label' => 'Iklan Meta (FB/IG)', 'utm' => ['facebook', 'fb', 'instagram', 'ig', 'meta', 'fbads', 'facebook_ads']],
        'tiktok' => ['label' => 'Iklan TikTok', 'utm' => ['tiktok', 'tt', 'tiktok_ads']],
        'google' => ['label' => 'Google', 'utm' => ['google', 'gads', 'adwords', 'youtube']],
        'whatsapp' => ['label' => 'WhatsApp', 'utm' => ['whatsapp', 'wa']],
        'organic' => ['label' => 'Organik / langsung', 'utm' => []],
        'other' => ['label' => 'Sumber lain', 'utm' => []],
    ];

    /** Ad platform → traffic source it pays for. */
    public const AD_PLATFORM = ['meta' => 'meta', 'tiktok' => 'tiktok', 'google' => 'google', 'other' => 'other'];

    public static function of(?string $utmSource): string
    {
        $u = strtolower(trim((string) $utmSource));
        if ($u === '' || $u === 'direct' || $u === 'manual') {
            return 'organic';
        }
        foreach (self::SOURCES as $key => $s) {
            if (in_array($u, $s['utm'], true)) {
                return $key;
            }
        }

        return 'other';
    }

    public static function label(string $key): string
    {
        return self::SOURCES[$key]['label'] ?? 'Sumber lain';
    }

    /** Constrain an orders query to one traffic source. */
    public static function scope($q, string $key)
    {
        $known = collect(self::SOURCES)->flatMap(fn ($s) => $s['utm'])->all();

        return match ($key) {
            'organic' => $q->where(fn ($w) => $w->whereNull('utm_source')->orWhereIn('utm_source', ['', 'direct', 'manual'])),
            'other' => $q->whereNotNull('utm_source')->whereNotIn('utm_source', array_merge($known, ['', 'direct', 'manual'])),
            default => $q->whereIn('utm_source', self::SOURCES[$key]['utm'] ?? []),
        };
    }
}
