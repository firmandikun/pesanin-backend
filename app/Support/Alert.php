<?php

namespace App\Support;

use App\Models\User;
use App\Support\Telegram\TelegramClient;
use Throwable;

/**
 * One way to alert a seller: the bell (in-app + Web Push + realtime) and, when connected, the seller's own
 * Telegram bot (Pengaturan → Notifikasi → Telegram, same bot as the scheduled AWB PDFs).
 * Each alert kind can be switched off in Pengaturan → Notifikasi (settings.notify.<kind>, on by default).
 */
class Alert
{
    /** Alert kinds sellers can switch off (Pengaturan → Notifikasi). Ads use settings.ads_guard.notify instead. */
    public const KINDS = ['daily_summary', 'ship_deadline', 'restock', 'money_check', 'missing_hpp', 'activation'];

    public static function enabled(User $user, string $kind): bool
    {
        return (bool) ($user->settings['notify'][$kind] ?? true);
    }

    public static function send(User $user, string $type, string $title, ?string $body, ?string $url, string $icon = '🔔', string $footer = ''): void
    {
        Notifier::send($user->id, $type, $title, $body, $url);
        self::telegram($user, trim("{$icon} {$title}\n".($body ? "\n{$body}" : '').($footer ? "\n\n{$footer}" : '')));
    }

    /** Telegram is a bonus channel: failures never stop the in-app notification. */
    public static function telegram(User $user, string $text): bool
    {
        try {
            $i = $user->integration('telegram');
            if ($i && $i->key('bot_token') && ! empty($i->settings['chat_id'])) {
                (new TelegramClient($i->key('bot_token')))->sendMessage($i->settings['chat_id'], $text);

                return true;
            }
        } catch (Throwable) {
            // ignore
        }

        return false;
    }
}
