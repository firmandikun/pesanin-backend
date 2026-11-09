<?php

namespace App\Support\WhatsApp;

use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\WhatsAppChatController;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\WaMessage;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends due broadcasts a small batch at a time (run every few seconds by the scheduler), staying under
 * the number's Meta messaging tier for the rolling 24h. Rate-limit errors leave recipients queued.
 */
class BroadcastSender
{
    public const BATCH = 20;

    private const RATE_LIMIT_CODES = [4, 80007, 130429, 131056];

    public static function run(?int $batch = null): int
    {
        $sent = 0;
        $due = Broadcast::whereIn('status', ['scheduled', 'sending'])->where('scheduled_at', '<=', now())->with('user')->orderBy('scheduled_at')->get();
        foreach ($due as $b) {
            $sent += self::step($b, $batch ?? self::BATCH);
        }

        return $sent;
    }

    public static function step(Broadcast $b, int $batch): int
    {
        $i = $b->user ? WhatsAppChatController::integration($b->user) : null;
        $wa = WhatsAppCloud::for($i);
        if (! $wa || ($i->settings['phone_number_id'] ?? null) !== $b->phone_number_id) {
            $b->recipients()->where('status', 'queued')->update(['status' => 'failed', 'error' => 'WhatsApp terputus atau nomor bisnis berubah.']);
            $b->update(['status' => 'done', 'finished_at' => now()]);

            return 0;
        }
        if ($b->status === 'scheduled') {
            $b->update(['status' => 'sending', 'started_at' => now()]);
        }

        // Stay under the Meta tier (business-initiated conversations per rolling 24h).
        $tier = Cache::remember("wa:limits:{$i->id}", 300, function () use ($wa) {
            try {
                return $wa->limits();
            } catch (Throwable) {
                return [];
            }
        })['messaging_limit_tier'] ?? null;
        $capacity = $tier ? (BroadcastController::TIERS[$tier] ?? null) : 250;
        if ($capacity !== null) {
            $batch = min($batch, $capacity - BroadcastController::sentSince($b->user, now()->subDay()));
        }

        $count = 0;
        foreach ($batch > 0 ? $b->recipients()->where('status', 'queued')->orderBy('id')->limit($batch)->get() : [] as $r) {
            $params = [];
            foreach (array_keys($b->variables ?? []) as $k) {
                $params[] = $b->value($k + 1, $r->name);
            }
            try {
                $res = $wa->sendTemplate($r->phone, $b->template_name, $b->template_language, $params);
            } catch (Throwable $e) {
                if (in_array($e->getCode(), self::RATE_LIMIT_CODES, true)) {
                    break; // try again on the next run
                }
                $r->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);

                continue;
            }
            $mid = $res['messages'][0]['id'] ?? null;
            $r->update(['status' => 'sent', 'message_id' => $mid, 'sent_at' => now(), 'error' => null]);
            // Keep it in the WhatsApp chat so replies have context.
            if ($mid) {
                WaMessage::firstOrCreate(['message_id' => $mid], [
                    'user_id' => $b->user_id, 'phone_number_id' => $b->phone_number_id, 'wa_id' => $res['contacts'][0]['wa_id'] ?? $r->phone,
                    'contact_name' => $r->name, 'direction' => 'out', 'type' => 'template', 'text' => $b->render($r->name),
                    'status' => 'sent', 'read' => true, 'sent_at' => now(),
                ]);
            }
            $count++;
        }

        if (! $b->recipients()->where('status', 'queued')->exists()) {
            $b->update(['status' => 'done', 'finished_at' => now()]);
        }

        return $count;
    }

    /** Delivery status from the webhook; never moves backwards (read > delivered > sent). */
    public static function status(array $s): void
    {
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $new = $s['status'] ?? null;
        if (! isset($rank[$new]) || empty($s['id'])) {
            return;
        }
        foreach (BroadcastRecipient::where('message_id', $s['id'])->get() as $r) {
            if (($rank[$r->status] ?? 0) < $rank[$new] || $new === 'failed') {
                $err = $s['errors'][0] ?? null;
                $r->update(['status' => $new, 'error' => $err ? mb_substr(($err['error_data']['details'] ?? $err['message'] ?? $err['title'] ?? 'Gagal terkirim'), 0, 300) : $r->error]);
            }
        }
    }
}
