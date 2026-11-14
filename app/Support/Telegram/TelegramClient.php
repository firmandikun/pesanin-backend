<?php

namespace App\Support\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Minimal Telegram Bot API client (the seller's own bot, created with @BotFather). */
class TelegramClient
{
    public function __construct(private string $token) {}

    private function url(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token}/{$method}";
    }

    private function check($res): array
    {
        $body = $res->json() ?? [];
        if (! $res->successful() || ! ($body['ok'] ?? false)) {
            throw new RuntimeException($body['description'] ?? 'Telegram tidak merespons');
        }

        return $body['result'] ?? [];
    }

    /** @return array{id:int, username:string, first_name:string} */
    public function me(): array
    {
        return $this->check(Http::timeout(15)->get($this->url('getMe')));
    }

    /** Most recent private chat that wrote to the bot (the seller sends /start once). */
    public function latestChat(): ?array
    {
        $updates = $this->check(Http::timeout(15)->get($this->url('getUpdates'), ['limit' => 100]));
        foreach (array_reverse($updates) as $u) {
            $chat = ($u['message'] ?? $u['my_chat_member'] ?? [])['chat'] ?? null;
            if ($chat && isset($chat['id'])) {
                $name = trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['title'] ?? $chat['username'] ?? (string) $chat['id']);

                return ['id' => (string) $chat['id'], 'name' => $name];
            }
        }

        return null;
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $this->check(Http::timeout(20)->post($this->url('sendMessage'), ['chat_id' => $chatId, 'text' => $text]));
    }

    public function sendDocument(string $chatId, string $path, string $filename, string $caption = ''): void
    {
        $this->check(Http::timeout(120)->attach('document', file_get_contents($path), $filename)
            ->post($this->url('sendDocument'), ['chat_id' => $chatId, 'caption' => mb_substr($caption, 0, 1000)]));
    }
}
