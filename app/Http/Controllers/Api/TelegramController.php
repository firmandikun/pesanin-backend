<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserIntegration;
use App\Support\AwbSchedule;
use App\Support\Telegram\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The seller's own Telegram bot (from @BotFather), used to receive the scheduled Shopee AWB PDF.
 * The bot token is stored encrypted and never returned to the browser.
 */
class TelegramController extends Controller
{
    public function show(Request $request)
    {
        return $this->present($request->user()->integrations()->where('provider', 'telegram')->first());
    }

    /** Verify the token with Telegram, save it, and pick up the chat if the seller already sent /start. */
    public function save(Request $request)
    {
        $data = $request->validate(['bot_token' => ['required', 'string', 'max:100', 'regex:/^\d+:[A-Za-z0-9_-]{30,}$/']], [
            'bot_token.regex' => 'Format token tidak dikenali. Salin token lengkap dari @BotFather (contoh 123456789:AAH...).',
        ]);
        $client = new TelegramClient($data['bot_token']);
        try {
            $me = $client->me();
        } catch (RuntimeException $e) {
            abort(422, 'Token bot tidak valid: '.$e->getMessage());
        }
        $user = $request->user();
        $i = $user->integrations()->where('provider', 'telegram')->first()
            ?? new UserIntegration(['user_id' => $user->id, 'provider' => 'telegram', 'webhook_token' => Str::random(40)]);
        $settings = ['bot_username' => $me['username'] ?? null, 'bot_name' => $me['first_name'] ?? null, 'bot_id' => $me['id'] ?? null];
        try {
            if ($chat = $client->latestChat()) {
                $settings += ['chat_id' => $chat['id'], 'chat_name' => $chat['name']];
            }
        } catch (RuntimeException) {
        }
        $i->fill(['credentials' => ['bot_token' => $data['bot_token']], 'settings' => $settings, 'status' => 'active', 'is_production' => true, 'verified_at' => now(), 'last_error' => null])->save();

        return $this->present($i);
    }

    /** After the seller sends /start to the bot. */
    public function detect(Request $request)
    {
        $i = $this->integration($request);
        try {
            $client = new TelegramClient($i->key('bot_token'));
            // Bots connected before the display name was stored: fetch it now so the seller can find the right chat.
            if (empty($i->settings['bot_name'])) {
                $i->settings = array_merge($i->settings ?? [], ['bot_name' => $client->me()['first_name'] ?? null]);
                $i->save();
            }
            $chat = $client->latestChat();
        } catch (RuntimeException $e) {
            abort(422, 'Telegram: '.$e->getMessage());
        }
        $bot = '@'.($i->settings['bot_username'] ?? 'bot').(! empty($i->settings['bot_name']) ? ' (nama di Telegram: "'.$i->settings['bot_name'].'")' : '');
        abort_unless($chat, 422, "Belum ada pesan ke bot {$bot}. Buka t.me/".($i->settings['bot_username'] ?? '').', kirim /start, lalu coba lagi.');
        $i->update(['settings' => array_merge($i->settings ?? [], ['chat_id' => $chat['id'], 'chat_name' => $chat['name']])]);

        return $this->present($i);
    }

    public function test(Request $request)
    {
        $i = $this->integration($request);
        abort_if(empty($i->settings['chat_id']), 422, 'Chat belum terdeteksi. Kirim /start ke bot dulu.');
        try {
            (new TelegramClient($i->key('bot_token')))->sendMessage($i->settings['chat_id'], 'Pesanin terhubung. Resi Shopee terjadwal akan dikirim ke chat ini.');
        } catch (RuntimeException $e) {
            abort(422, 'Telegram: '.$e->getMessage());
        }

        return ['ok' => true];
    }

    /** "Kirim resi sekarang": runs detached (downloading many labels can outlast the proxy timeout). */
    public function sendNow(Request $request)
    {
        $user = $request->user();
        $scope = $request->validate(['scope' => 'nullable|in:new,all'])['scope'] ?? 'new';
        try {
            AwbSchedule::telegram($user);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $php = (new PhpExecutableFinder)->find(false);
        if ($php && function_exists('exec') && ! app()->runningUnitTests()) {
            @exec(sprintf('nohup %s %s awb:send %d%s >> %s 2>&1 &',
                escapeshellarg($php), escapeshellarg(base_path('artisan')), $user->id, $scope === 'all' ? ' --all' : '', escapeshellarg(storage_path('logs/awb-send.log'))));

            return ['started' => true];
        }

        return ['started' => true, 'result' => AwbSchedule::send($user, 'Kirim manual', $scope)];
    }

    public function destroy(Request $request)
    {
        $request->user()->integrations()->where('provider', 'telegram')->delete();

        return response()->noContent();
    }

    private function integration(Request $request): UserIntegration
    {
        $i = $request->user()->integrations()->where('provider', 'telegram')->where('status', 'active')->first();
        abort_unless($i, 422, 'Telegram belum terhubung.');

        return $i;
    }

    private function present(?UserIntegration $i): array
    {
        return [
            'connected' => (bool) $i,
            'bot_username' => $i?->settings['bot_username'] ?? null,
            'bot_name' => $i?->settings['bot_name'] ?? null,
            'chat_name' => $i?->settings['chat_name'] ?? null,
            'ready' => (bool) ($i && ! empty($i->settings['chat_id'])),
        ];
    }
}
