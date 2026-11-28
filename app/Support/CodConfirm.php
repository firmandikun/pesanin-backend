<?php

namespace App\Support;

use App\Http\Controllers\Api\WhatsAppChatController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\WaMessage;
use App\Support\WhatsApp\WhatsAppCloud;
use Throwable;

/**
 * Konfirmasi COD otomatis: right after a COD order comes in, the buyer gets a WhatsApp template asking to reply
 * "YA" (or tap a button). The parcel is held — auto resi skips it — until the buyer confirms; a "batal" reply or no
 * reply in time alerts the seller/CS. Low-risk buyers (by Risiko COD) can be skipped so loyal customers aren't asked.
 *
 * Settings in user settings "automation": cod_confirm, cod_confirm_template {name, language, variables},
 * cod_confirm_level (all | medium | high) and cod_confirm_hours.
 */
class CodConfirm
{
    /** Statuses that keep a COD parcel from being shipped automatically. */
    public const HOLD = ['waiting', 'declined', 'no_reply', 'failed'];

    public const LABELS = [
        'skipped' => 'Tidak perlu konfirmasi', 'waiting' => 'Menunggu konfirmasi', 'confirmed' => 'Dikonfirmasi pembeli',
        'declined' => 'Pembeli membatalkan', 'no_reply' => 'Tidak membalas', 'failed' => 'WA konfirmasi gagal terkirim',
    ];

    private const YES = '/^(ya+|iya+|y|yes|ok|oke+|okay|siap|benar|betul|lanjut|lanjutkan|setuju|konfirmasi|jadi|kirim)\b/u';

    private const NO = '/^(tidak|tdk|gak|ga|nggak|enggak|engga|no|batal|batalkan|cancel|cancelled|gajadi|ga jadi|gak jadi|tidak jadi)\b/u';

    public static function settings(User $user): array
    {
        $a = OrderAutomation::settings($user);

        return [
            'enabled' => (bool) ($a['cod_confirm'] ?? false), 'template' => $a['cod_confirm_template'] ?? null,
            'level' => in_array($a['cod_confirm_level'] ?? null, ['all', 'medium', 'high'], true) ? $a['cod_confirm_level'] : 'all',
            'hours' => max(1, min(72, (int) ($a['cod_confirm_hours'] ?? 12))),
        ];
    }

    /** On, with a template and a connected WhatsApp number: only then are parcels held. */
    public static function active(User $user): bool
    {
        $s = self::settings($user);

        return $s['enabled'] && ! empty($s['template']['name']) && WhatsAppChatController::integration($user) !== null;
    }

    /** Should auto resi wait for this order? */
    public static function holds(Order $o, bool $active): bool
    {
        if (! $active || $o->payment_method !== 'cod') {
            return false;
        }
        // Orders from before the feature ran (older than the automation window) ship as before.
        return in_array($o->cod_confirm_status, self::HOLD, true)
            || ($o->cod_confirm_status === null && $o->created_at && $o->created_at->gte(now()->subDays(OrderAutomation::RECENT_DAYS)));
    }

    /** Every 30 s (orders:automate): send the confirmation for new COD orders, then flag the ones nobody answered. */
    public static function run(): int
    {
        $n = 0;
        foreach (User::where('settings->automation->cod_confirm', true)->get() as $user) {
            if (! self::active($user)) {
                continue;
            }
            $orders = self::pending($user)->whereNull('cod_confirm_status')->where('created_at', '>=', now()->subDays(OrderAutomation::RECENT_DAYS))
                ->orderBy('id')->limit(30)->get();
            CodRisk::attach($orders, $user->id);
            foreach ($orders as $o) {
                // cod_risk is a display attribute (not a column): read it, then drop it before saving.
                $level = $o->cod_risk['level'] ?? 'low';
                $o->offsetUnset('cod_risk');
                $n += self::ask($o, $user, $level) ? 1 : 0;
            }
            self::expire($user);
        }

        return $n;
    }

    private static function pending(User $user)
    {
        return Order::where('user_id', $user->id)->where('channel', '!=', 'shopee')->where('is_abandoned', false)->where('payment_method', 'cod')
            ->whereIn('status', ['pending', 'processing'])->where('shipping_status', 'not_shipped');
    }

    /** Send the WhatsApp template (or skip a low-risk buyer). Returns true when a message went out. */
    public static function ask(Order $o, User $user, string $level = 'low'): bool
    {
        $s = self::settings($user);
        $needed = match ($s['level']) {
            'high' => $level === 'high',
            'medium' => in_array($level, ['medium', 'high'], true),
            default => true,
        };
        if (! $needed) {
            $o->forceFill(['cod_confirm_status' => 'skipped'])->save();

            return false;
        }
        $integration = WhatsAppChatController::integration($user);
        $wa = WhatsAppCloud::for($integration);
        $to = Customer::normalizePhone($o->phone);
        try {
            abort_unless($wa && $to, 422, 'Nomor pembeli atau WhatsApp toko belum siap.');
            $o->loadMissing('product:id,name');
            $params = array_slice([$o->customer_name ?: 'Kak', $o->code, $o->product?->name ?? 'pesanan', 'Rp'.number_format((int) $o->total, 0, ',', '.')],
                0, (int) ($s['template']['variables'] ?? 2));
            $res = $wa->sendTemplate($to, $s['template']['name'], $s['template']['language'] ?? 'id', $params);
            if ($mid = $res['messages'][0]['id'] ?? null) {
                WaMessage::firstOrCreate(['message_id' => $mid], [
                    'user_id' => $user->id, 'phone_number_id' => $integration->settings['phone_number_id'] ?? null,
                    'wa_id' => $res['contacts'][0]['wa_id'] ?? $to, 'contact_name' => $o->customer_name, 'direction' => 'out', 'type' => 'template',
                    'text' => "[Konfirmasi COD] {$o->code} · Rp".number_format((int) $o->total, 0, ',', '.'), 'status' => 'sent', 'read' => true, 'sent_at' => now(),
                ]);
            }
            $o->forceFill(['cod_confirm_status' => 'waiting', 'cod_confirm_sent_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            $o->forceFill(['cod_confirm_status' => 'failed', 'cod_confirm_sent_at' => now()])->save();
            self::alert($o, 'WA konfirmasi COD gagal', "{$o->code}: ".mb_substr($e->getMessage(), 0, 150).'. Konfirmasi manual ke pembeli, lalu tandai di detail pesanan.');

            return false;
        }
    }

    /** Waiting longer than the seller's limit: flag "tidak membalas" so CS calls the buyer before shipping. */
    private static function expire(User $user): void
    {
        $s = self::settings($user);
        foreach (self::pending($user)->where('cod_confirm_status', 'waiting')->where('cod_confirm_sent_at', '<', now()->subHours($s['hours']))->limit(50)->get() as $o) {
            $o->forceFill(['cod_confirm_status' => 'no_reply'])->save();
            self::alert($o, 'Pembeli COD belum konfirmasi', "{$o->code} ({$o->customer_name}) belum membalas {$s['hours']} jam. Hubungi dulu sebelum dikirim.");
        }
    }

    /** Incoming WhatsApp message (webhook): a YA / BATAL reply settles the buyer's newest waiting COD order. */
    public static function reply(int $userId, string $waId, string $text): ?Order
    {
        $t = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text)));
        $yes = preg_match(self::YES, $t) === 1;
        $no = ! $yes && preg_match(self::NO, $t) === 1;
        if (! $yes && ! $no) {
            return null;
        }
        $phone = Customer::normalizePhone($waId);
        $order = Order::where('user_id', $userId)->whereIn('cod_confirm_status', ['waiting', 'no_reply', 'failed'])
            ->where('created_at', '>=', now()->subDays(7))->latest('id')->limit(50)->get()
            ->first(fn (Order $o) => Customer::normalizePhone($o->phone) === $phone);
        if (! $order) {
            return null;
        }
        $order->forceFill(['cod_confirm_status' => $yes ? 'confirmed' : 'declined', 'cod_confirmed_at' => now()])->save();
        if ($no) {
            self::alert($order, 'Pembeli membatalkan pesanan COD', "{$order->code} ({$order->customer_name}) membalas: \"".mb_strimwidth($text, 0, 60, '…').'". Batalkan pesanan atau hubungi pembeli.');
        }

        return $order;
    }

    /** Manual from the order detail (CS confirmed by phone, or the buyer cancelled in chat). */
    public static function set(Order $o, string $status): void
    {
        $o->forceFill(['cod_confirm_status' => $status, 'cod_confirmed_at' => in_array($status, ['confirmed', 'declined'], true) ? now() : null])->save();
    }

    /** Seller notification, plus the order's CS (if any) sees it through the order link. */
    private static function alert(Order $o, string $title, string $body): void
    {
        Notifier::send($o->user_id, 'order', $title, $body, '/orders?search='.$o->code, $o->id);
    }
}
