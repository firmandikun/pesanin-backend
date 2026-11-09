<?php

namespace App\Support\Meta;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Messenger + Instagram Direct for a seller's Facebook Pages (Conversations API, Page access tokens).
 * Reading works by polling, so it runs without a public webhook URL.
 */
class MetaChat
{
    /** Pages the user manages, with their Page token and linked Instagram professional account. */
    public static function pages(string $userToken): array
    {
        $json = self::call(Http::timeout(30)->get(MetaAds::graph('me/accounts'), [
            'fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username,profile_picture_url}', 'limit' => 100, 'access_token' => $userToken,
        ]));

        return collect($json['data'] ?? [])->map(fn ($p) => [
            'id' => (string) $p['id'], 'name' => $p['name'] ?? $p['id'], 'token' => $p['access_token'] ?? null,
            'picture' => $p['picture']['data']['url'] ?? null,
            'ig_id' => isset($p['instagram_business_account']['id']) ? (string) $p['instagram_business_account']['id'] : null,
            'ig_username' => $p['instagram_business_account']['username'] ?? null,
        ])->filter(fn ($p) => $p['token'])->values()->all();
    }

    /** Query for a Page's latest conversations on one platform (messenger | instagram). */
    public static function conversationQuery(string $platform, string $token, int $limit = 25): array
    {
        return [
            'platform' => $platform, 'limit' => $limit, 'access_token' => $token,
            'fields' => 'id,updated_time,unread_count,participants,messages.limit(1){id,message,from,created_time,attachments.limit(1){mime_type},shares.limit(1){link},story,is_unsupported}',
        ];
    }

    public static function conversations(string $pageId, string $token, string $platform, int $limit = 25): array
    {
        return self::call(Http::timeout(30)->get(MetaAds::graph("{$pageId}/conversations"), self::conversationQuery($platform, $token, $limit)))['data'] ?? [];
    }

    /** Same error handling as call(), for responses gathered with Http::pool. */
    public static function unwrap($res): array
    {
        return self::call($res);
    }

    public static function messages(string $conversationId, string $token, int $limit = 50): array
    {
        return self::call(Http::timeout(30)->get(MetaAds::graph($conversationId), [
            'fields' => "messages.limit({$limit}){id,message,from,created_time,attachments{mime_type,image_data,file_url,name},sticker,shares.limit(1){link},story,is_unsupported}", 'access_token' => $token,
        ]))['messages']['data'] ?? [];
    }

    /** Reply inside the 24-hour window (messaging_type RESPONSE) — same endpoint for Messenger and Instagram. */
    public static function send(string $pageId, string $token, string $recipientId, string $text): array
    {
        return self::call(Http::timeout(30)->asJson()->post(MetaAds::graph("{$pageId}/messages").'?access_token='.urlencode($token), [
            'recipient' => ['id' => $recipientId], 'message' => ['text' => $text], 'messaging_type' => 'RESPONSE',
        ]));
    }

    private static function call($res): array
    {
        $json = $res->json() ?? [];
        if ($res->failed() || isset($json['error'])) {
            $e = $json['error'] ?? [];
            $code = (int) ($e['code'] ?? 0);
            $sub = (int) ($e['error_subcode'] ?? 0);
            $raw = (string) ($e['error_user_msg'] ?? $e['message'] ?? "HTTP {$res->status()}");
            // Keep Meta's own words in the log: its codes are reused for different causes.
            \Illuminate\Support\Facades\Log::warning("Meta chat error {$code}/{$sub}: {$raw}");
            $outsideWindow = in_array($sub, [2018278, 2534022], true) || preg_match('/outside of (the )?allowed window|24 ?hour/i', $raw);
            throw new RuntimeException(match (true) {
                $code === 190 => 'Akses Facebook kedaluwarsa atau dicabut. Hubungkan ulang Facebook & Instagram.',
                $outsideWindow => 'Tidak bisa dibalas: sudah lewat 24 jam sejak pesan terakhir pembeli. Meta hanya mengizinkan balasan dalam 24 jam; balas lewat aplikasi Instagram/Messenger atau tunggu pembeli mengirim pesan lagi.',
                $sub === 2534041 => 'Akses pesan Instagram dimatikan. Di aplikasi Instagram: Pengaturan → Pesan dan balasan cerita → Alat yang terhubung → aktifkan "Izinkan akses ke pesan".',
                $sub === 2534014 || str_contains($raw, 'No matching user') => 'Penerima tidak ditemukan atau tidak bisa menerima pesan dari akun ini.',
                in_array($code, [10, 200, 230], true) => "Meta menolak akses pesan untuk akun ini ({$raw}). Pastikan Halaman dan Instagram-nya dicentang saat menghubungkan.",
                default => "Meta: {$raw}",
            });
        }

        return $json;
    }
}
