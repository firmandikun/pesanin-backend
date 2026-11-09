<?php

namespace App\Support\WhatsApp;

use App\Models\UserIntegration;
use App\Support\Meta\MetaAds;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** WhatsApp Business Cloud API (Meta) for one business phone number. */
class WhatsAppCloud
{
    public function __construct(private string $phoneNumberId, private string $token, private ?string $wabaId = null) {}

    public static function for(?UserIntegration $i): ?self
    {
        return $i && $i->provider === 'whatsapp_cloud' && $i->key('access_token') && ($i->settings['phone_number_id'] ?? null)
            ? new self($i->settings['phone_number_id'], $i->key('access_token'), $i->settings['waba_id'] ?? null) : null;
    }

    public function wabaId(): ?string
    {
        return $this->wabaId;
    }

    /**
     * Finds the WhatsApp Business Account that owns this number: the token's granted WABAs
     * (from debug_token), checked against their phone numbers.
     */
    public function detectWaba(): ?string
    {
        $app = config('services.meta.app_id').'|'.config('services.meta.app_secret');
        $scopes = Http::timeout(20)->get(MetaAds::graph('debug_token'), ['input_token' => $this->token, 'access_token' => $app])->json('data.granular_scopes') ?? [];
        $ids = collect($scopes)->whereIn('scope', ['whatsapp_business_management', 'whatsapp_business_messaging'])->pluck('target_ids')->flatten()->unique()->values();
        foreach ($ids as $id) {
            $phones = Http::timeout(20)->get(MetaAds::graph("{$id}/phone_numbers"), ['access_token' => $this->token, 'fields' => 'id'])->json('data') ?? [];
            if (collect($phones)->contains('id', $this->phoneNumberId)) {
                return $this->wabaId = (string) $id;
            }
        }

        return $this->wabaId = $ids->count() === 1 ? (string) $ids->first() : null;
    }

    /** Lets this app receive the WABA's webhooks (incoming messages, delivery statuses). */
    public function subscribeApp(): void
    {
        if ($this->wabaId) {
            $this->call(Http::timeout(20)->withToken($this->token)->post(MetaAds::graph("{$this->wabaId}/subscribed_apps")));
        }
    }

    /** Sending tier and quality of the number, for broadcast pacing. */
    public function limits(): array
    {
        return $this->call(Http::timeout(20)->get(MetaAds::graph($this->phoneNumberId), [
            'fields' => 'display_phone_number,verified_name,quality_rating,messaging_limit_tier,throughput', 'access_token' => $this->token,
        ]));
    }

    public function templates(): array
    {
        $this->needWaba();

        return $this->call(Http::timeout(20)->get(MetaAds::graph("{$this->wabaId}/message_templates"), [
            'fields' => 'id,name,language,status,category,components,rejected_reason', 'limit' => 200, 'access_token' => $this->token,
        ]))['data'] ?? [];
    }

    public function createTemplate(array $payload): array
    {
        $this->needWaba();

        return $this->call(Http::timeout(20)->withToken($this->token)->asJson()->post(MetaAds::graph("{$this->wabaId}/message_templates"), $payload));
    }

    public function deleteTemplate(string $name): void
    {
        $this->needWaba();
        $this->call(Http::timeout(20)->withToken($this->token)->delete(MetaAds::graph("{$this->wabaId}/message_templates").'?'.http_build_query(['name' => $name])));
    }

    /** Template message; $params fill the body's {{1}}, {{2}}… in order. */
    public function sendTemplate(string $to, string $name, string $language, array $params = []): array
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($params) {
            $template['components'] = [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => $p === '' ? '-' : $p], $params)]];
        }

        return $this->post(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'template', 'template' => $template]);
    }

    private function needWaba(): void
    {
        if (! $this->wabaId) {
            throw new RuntimeException('ID WhatsApp Business Account belum diketahui. Simpan ulang WhatsApp di Pengaturan → Integrasi, atau isi WABA ID-nya.');
        }
    }

    /** Number details; also proves the token can use this number. */
    public function info(): array
    {
        return $this->call(Http::timeout(20)->get(MetaAds::graph($this->phoneNumberId), [
            'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status', 'access_token' => $this->token,
        ]));
    }

    public function sendText(string $to, string $text): array
    {
        return $this->post(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => $text]]);
    }

    /** Blue ticks for the customer. */
    public function markRead(string $messageId): void
    {
        $this->post(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $messageId]);
    }

    private function post(array $body): array
    {
        return $this->call(Http::timeout(20)->withToken($this->token)->asJson()->post(MetaAds::graph("{$this->phoneNumberId}/messages"), $body));
    }

    private function call($res): array
    {
        $json = $res->json() ?? [];
        if ($res->failed() || isset($json['error'])) {
            $e = $json['error'] ?? [];
            $code = (int) ($e['code'] ?? 0);
            $raw = (string) ($e['error_data']['details'] ?? $e['error_user_msg'] ?? $e['message'] ?? "HTTP {$res->status()}");
            Log::warning("WhatsApp Cloud error {$code}: {$raw}");
            throw new RuntimeException(match (true) {
                $code === 190 => 'Token WhatsApp kedaluwarsa atau tidak valid. Buat token baru (token permanen dari System User disarankan).',
                $code === 131047 || str_contains($raw, '24 hours') => 'Tidak bisa dibalas: sudah lewat 24 jam sejak pesan terakhir pelanggan. Di luar 24 jam WhatsApp hanya mengizinkan pesan template.',
                $code === 131030 => 'Nomor tujuan belum ada di daftar penerima uji. Tambahkan dulu di Meta → WhatsApp → API Setup (nomor uji hanya boleh kirim ke maks. 5 nomor).',
                $code === 100 && str_contains($raw, 'phone number') => 'Phone Number ID tidak ditemukan untuk token ini.',
                $code === 132001 => 'Template tidak ditemukan atau belum disetujui untuk bahasa ini.',
                $code === 132000 => 'Jumlah variabel tidak sesuai dengan template.',
                in_array($code, [131026, 131051], true) => 'Nomor ini tidak terdaftar di WhatsApp atau tidak bisa menerima pesan.',
                $code === 131049 => 'Meta menahan pesan pemasaran ke nomor ini agar pengguna tidak menerima terlalu banyak promosi. Coba lagi nanti.',
                in_array($code, [130429, 131056, 4, 80007], true) => 'Batas kecepatan kirim WhatsApp tercapai. Pengiriman dilanjutkan otomatis.',
                default => "WhatsApp: {$raw}",
            }, $code);
        }

        return $json;
    }
}
