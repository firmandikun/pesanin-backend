<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserIntegration;
use App\Models\WaMessage;
use App\Support\Notifier;
use App\Support\WhatsApp\WhatsAppCloud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * WhatsApp Business (Cloud API) in the shared /chat inbox. Messages arrive on the seller's webhook
 * (/api/whatsapp/webhook/{token}), are stored in wa_messages, and replies go out through the Cloud API.
 */
class WhatsAppChatController extends Controller
{
    /** Meta's subscription check: echo hub.challenge when the verify token matches this connection. */
    public function verify(Request $request, string $token)
    {
        $i = UserIntegration::where('provider', 'whatsapp_cloud')->where('webhook_token', $token)->first();
        abort_unless($i && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $token)
    {
        $i = UserIntegration::where('provider', 'whatsapp_cloud')->where('webhook_token', $token)->first();
        abort_unless($i, 404);
        // With the App Secret set, only Meta-signed payloads are accepted.
        if ($secret = $i->key('app_secret')) {
            $sig = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
            if (! hash_equals($sig, (string) $request->header('X-Hub-Signature-256'))) {
                Log::warning('WhatsApp webhook: invalid signature (check App Secret)', ['integration' => $i->id]);
                abort(403);
            }
        }
        Log::info('WhatsApp webhook received', ['integration' => $i->id, 'fields' => collect($request->input('entry', []))->flatMap(fn ($e) => array_column($e['changes'] ?? [], 'field'))->all()]);

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $v = $change['value'] ?? [];
                $pnid = (string) ($v['metadata']['phone_number_id'] ?? '');
                if ($pnid !== ($i->settings['phone_number_id'] ?? null)) {
                    continue;
                }
                $names = collect($v['contacts'] ?? [])->mapWithKeys(fn ($c) => [$c['wa_id'] => $c['profile']['name'] ?? null]);
                foreach ($v['messages'] ?? [] as $m) {
                    $msg = WaMessage::firstOrCreate(['message_id' => $m['id']], [
                        'user_id' => $i->user_id, 'phone_number_id' => $pnid, 'wa_id' => $m['from'],
                        'contact_name' => $names[$m['from']] ?? null, 'direction' => 'in', 'type' => $m['type'] ?? 'text',
                        'text' => self::incomingText($m), 'media_id' => $m[$m['type'] ?? '']['id'] ?? null,
                        'sent_at' => Carbon::createFromTimestamp((int) ($m['timestamp'] ?? time())),
                    ]);
                    if ($msg->wasRecentlyCreated) {
                        Notifier::send($i->user_id, 'chat', 'Pesan WhatsApp baru',
                            mb_strimwidth(($msg->contact_name ?: '+'.$msg->wa_id).': '.$msg->text, 0, 120, '…'),
                            "/chat?channel=whatsapp&source={$pnid}&c={$msg->wa_id}");
                    }
                }
                foreach ($v['statuses'] ?? [] as $s) {
                    WaMessage::where('message_id', $s['id'])->where('direction', 'out')->update(['status' => $s['status'] ?? null]);
                    \App\Support\WhatsApp\BroadcastSender::status($s);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /** One conversation per customer number, in the shared Conv shape. */
    public static function inbox(User $user, ?string $source = null): array
    {
        $i = self::integration($user);
        if (! $i) {
            return [];
        }
        $pnid = (string) ($i->settings['phone_number_id'] ?? '');
        if ($source && $source !== $pnid) {
            return [];
        }
        $rows = WaMessage::where('user_id', $user->id)->where('phone_number_id', $pnid)->orderByDesc('sent_at')->orderByDesc('id')->limit(2000)->get()->groupBy('wa_id');

        return $rows->map(function ($msgs, $waId) use ($i, $pnid) {
            $last = $msgs->first();

            return [
                'channel' => 'whatsapp', 'source_id' => $pnid, 'source_name' => $i->settings['display_phone'] ?? 'WhatsApp',
                'id' => (string) $waId, 'to_id' => (string) $waId,
                'name' => $msgs->firstWhere('contact_name')?->contact_name ?: '+'.$waId, 'avatar' => null,
                'unread' => $msgs->where('direction', 'in')->where('read', false)->count(), 'pinned' => false,
                'last_text' => $last->text ?? '', 'last_from_me' => $last->direction === 'out', 'last_message_id' => $last->message_id,
                'at' => $last->sent_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    public function messages(Request $request, string $phone, string $waId)
    {
        $i = $this->own($request, $phone);
        $rows = WaMessage::where('user_id', $request->user()->id)->where('phone_number_id', $phone)->where('wa_id', $waId)->orderBy('sent_at')->orderBy('id')->limit(300)->get();
        // Opening the chat reads it: clear the badge and send blue ticks for the latest message.
        $unread = $rows->where('direction', 'in')->where('read', false);
        if ($unread->isNotEmpty()) {
            WaMessage::whereIn('id', $unread->pluck('id'))->update(['read' => true]);
            try {
                WhatsAppCloud::for($i)?->markRead($unread->last()->message_id);
            } catch (Throwable) {
                // read receipts are best effort
            }
        }

        return ['messages' => $rows->map(fn ($m) => [
            'id' => (string) ($m->message_id ?? $m->id), 'from_me' => $m->direction === 'out', 'type' => $m->type,
            'text' => $m->text ?? '', 'image' => null, 'status' => $m->status, 'at' => $m->sent_at?->toIso8601String(),
        ])->values()];
    }

    public function send(Request $request, string $phone)
    {
        $i = $this->own($request, $phone);
        $d = $request->validate(['to_id' => 'required|string|regex:/^\d{6,20}$/', 'text' => 'required|string|max:4096']);
        try {
            $r = WhatsAppCloud::for($i)->sendText($d['to_id'], $d['text']);
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }
        $m = WaMessage::create([
            'user_id' => $request->user()->id, 'phone_number_id' => $phone, 'wa_id' => $d['to_id'], 'direction' => 'out',
            'message_id' => $r['messages'][0]['id'] ?? null, 'type' => 'text', 'text' => $d['text'], 'status' => 'sent', 'sent_at' => now(),
        ]);

        return ['message' => ['id' => (string) ($m->message_id ?? $m->id), 'from_me' => true, 'type' => 'text', 'text' => $m->text, 'image' => null, 'status' => 'sent', 'at' => $m->sent_at->toIso8601String()]];
    }

    public static function integration(User $user): ?UserIntegration
    {
        return UserIntegration::where('user_id', $user->id)->where('provider', 'whatsapp_cloud')->where('status', 'active')->first();
    }

    private function own(Request $request, string $phone): UserIntegration
    {
        $i = self::integration($request->user());
        abort_unless($i && ($i->settings['phone_number_id'] ?? null) === $phone, 404);

        return $i;
    }

    /** Text for the inbox; media and other types get a label. */
    private static function incomingText(array $m): string
    {
        $type = $m['type'] ?? 'text';

        return match ($type) {
            'text' => (string) ($m['text']['body'] ?? ''),
            'image' => '[Gambar]'.(! empty($m['image']['caption']) ? ' '.$m['image']['caption'] : ''),
            'video' => '[Video]'.(! empty($m['video']['caption']) ? ' '.$m['video']['caption'] : ''),
            'audio' => '[Pesan suara]', 'document' => '[Dokumen] '.($m['document']['filename'] ?? ''), 'sticker' => '[Stiker]',
            'location' => '[Lokasi] '.($m['location']['name'] ?? trim(($m['location']['latitude'] ?? '').','.($m['location']['longitude'] ?? ''), ',')),
            'contacts' => '[Kontak]', 'reaction' => '[Reaksi '.($m['reaction']['emoji'] ?? '').']',
            'button' => (string) ($m['button']['text'] ?? '[Tombol]'),
            'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '[Pilihan]'),
            default => '[Pesan]',
        };
    }
}
