<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\Member;
use App\Support\Plans;
use App\Support\WhatsApp\WhatsAppCloud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WhatsApp broadcast: approved message templates sent to many customers. Recipients are queued here
 * and sent gradually by `broadcast:send`; delivery statuses come back through the WhatsApp webhook.
 */
class BroadcastController extends Controller
{
    /** Business-initiated messages per rolling 24h for each Meta messaging tier. */
    public const TIERS = ['TIER_50' => 50, 'TIER_250' => 250, 'TIER_1K' => 1000, 'TIER_2K' => 2000, 'TIER_10K' => 10000, 'TIER_100K' => 100000, 'TIER_UNLIMITED' => null];

    private function integration(User $user): ?UserIntegration
    {
        return WhatsAppChatController::integration($user);
    }

    private function client(Request $request): WhatsAppCloud
    {
        $i = $this->integration($request->user());
        abort_unless($i, 422, 'WhatsApp belum terhubung. Hubungkan di Pengaturan → Integrasi.');
        if (empty($i->settings['waba_id'])) {
            IntegrationController::linkWaba($i);
            $i->save();
        }
        $wa = WhatsAppCloud::for($i);
        if (! $wa->wabaId()) {
            // Usually an expired token: surface that instead of "WABA unknown".
            try {
                $wa->info();
            } catch (Throwable $e) {
                abort(422, $e->getMessage());
            }
        }

        return $wa;
    }

    /** Number health, sending capacity and plan quota for the Broadcast page. */
    public function status(Request $request)
    {
        $user = $request->user();
        $i = $this->integration($user);
        $plan = Plans::current($user);
        $out = [
            'connected' => (bool) $i, 'phone' => $i?->settings['display_phone'] ?? null, 'name' => $i?->settings['verified_name'] ?? null,
            'waba_id' => $i?->settings['waba_id'] ?? null, 'quality' => null, 'tier' => null, 'capacity' => null,
            'used_24h' => self::sentSince($user, now()->subDay()),
            'plan' => ['name' => Plans::all()[$plan]['name'], 'limit' => Plans::all()[$plan]['limits']['broadcast'], 'used' => Plans::usage($user)['broadcast']],
            'customers_with_phone' => Customer::where('user_id', $user->id)->whereNotNull('phone')->count(),
            'opted_in' => Customer::where('user_id', $user->id)->whereNotNull('phone')->where('opt_in', true)->count(),
            'error' => null,
        ];
        if ($i) {
            try {
                $l = Cache::remember("wa:limits:{$i->id}", 300, fn () => WhatsAppCloud::for($i)->limits());
                $out['quality'] = $l['quality_rating'] ?? null;
                $out['tier'] = $l['messaging_limit_tier'] ?? null;
                $out['capacity'] = self::TIERS[$out['tier']] ?? ($out['tier'] ? null : 250);
            } catch (Throwable $e) {
                $out['error'] = $e->getMessage();
            }
            if (empty($i->settings['waba_id'])) {
                IntegrationController::linkWaba($i);
                $i->save();
                $out['waba_id'] = $i->settings['waba_id'] ?? null;
            }
        }

        return $out;
    }

    public static function sentSince(User $user, $since): int
    {
        return BroadcastRecipient::whereHas('broadcast', fn ($b) => $b->where('user_id', $user->id))
            ->whereNotNull('sent_at')->where('sent_at', '>=', $since)->count();
    }

    // ── Templates ─────────────────────────────────────────────

    public function templates(Request $request)
    {
        try {
            $rows = $this->client($request)->templates();
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }

        return ['data' => array_map([self::class, 'presentTemplate'], $rows)];
    }

    /** Flattens a Meta template; `supported` = Pesanin can fill it (text only, body variables only). */
    public static function presentTemplate(array $t): array
    {
        $by = collect($t['components'] ?? [])->keyBy(fn ($c) => strtolower($c['type'] ?? ''));
        $body = (string) ($by['body']['text'] ?? '');
        $header = $by['header'] ?? null;
        $buttons = $by['buttons']['buttons'] ?? [];
        preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
        $reason = match (true) {
            $header && strtoupper($header['format'] ?? 'TEXT') !== 'TEXT' => 'Header '.strtolower($header['format']).' belum didukung',
            $header && str_contains((string) ($header['text'] ?? ''), '{{') => 'Variabel di header belum didukung',
            collect($buttons)->contains(fn ($b) => str_contains((string) ($b['url'] ?? ''), '{{')) => 'Tombol URL dinamis belum didukung',
            default => null,
        };

        return [
            'id' => $t['id'] ?? null, 'name' => $t['name'], 'language' => $t['language'] ?? 'id', 'status' => $t['status'] ?? null,
            'category' => $t['category'] ?? null, 'rejected_reason' => $t['rejected_reason'] ?? null,
            'header' => $header && strtoupper($header['format'] ?? 'TEXT') === 'TEXT' ? ($header['text'] ?? null) : null,
            'body' => $body, 'footer' => $by['footer']['text'] ?? null,
            'buttons' => array_map(fn ($b) => $b['text'] ?? '', $buttons),
            'variables' => count(array_unique($m[1])),
            'supported' => $reason === null, 'unsupported_reason' => $reason,
        ];
    }

    public function storeTemplate(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'language' => 'required|string|in:id,en,en_US,ms',
            'category' => 'required|in:MARKETING,UTILITY',
            'header' => 'nullable|string|max:60',
            'body' => 'required|string|max:1024',
            'footer' => 'nullable|string|max:60',
            'examples' => 'nullable|array', 'examples.*' => 'nullable|string|max:100',
            'buttons' => 'nullable|array|max:3', 'buttons.*' => 'string|max:25',
        ], ['name.regex' => 'Nama template hanya boleh huruf kecil, angka, dan garis bawah (_).']);
        preg_match_all('/\{\{(\d+)\}\}/', $d['body'], $m);
        $nums = array_map('intval', array_unique($m[1]));
        sort($nums);
        abort_if($nums && $nums !== range(1, count($nums)), 422, 'Variabel harus berurutan mulai {{1}}, {{2}}, dst.');
        abort_if(preg_match('/^\s*\{\{|\{\{\d+\}\}\s*$/', trim($d['body'])), 422, 'Isi pesan tidak boleh diawali atau diakhiri variabel (aturan Meta).');

        $components = [];
        if (! empty($d['header'])) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $d['header']];
        }
        $body = ['type' => 'BODY', 'text' => $d['body']];
        if ($nums) {
            $body['example'] = ['body_text' => [array_map(fn ($n) => ($d['examples'][$n - 1] ?? '') ?: "contoh{$n}", $nums)]];
        }
        $components[] = $body;
        if (! empty($d['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $d['footer']];
        }
        if (! empty($d['buttons'])) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => array_map(fn ($t) => ['type' => 'QUICK_REPLY', 'text' => $t], array_values(array_filter($d['buttons'])))];
        }
        try {
            $r = $this->client($request)->createTemplate(['name' => $d['name'], 'language' => $d['language'], 'category' => $d['category'], 'components' => $components]);
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }

        return ['id' => $r['id'] ?? null, 'status' => $r['status'] ?? 'PENDING'];
    }

    public function deleteTemplate(Request $request, string $name)
    {
        abort_unless(preg_match('/^[a-z0-9_]+$/', $name), 404);
        try {
            $this->client($request)->deleteTemplate($name);
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }

        return response()->noContent();
    }

    // ── Broadcasts ────────────────────────────────────────────

    public function index(Request $request)
    {
        $q = Broadcast::where('user_id', $request->user()->id)->withStats()
            ->when($request->query('tab') === 'active', fn ($w) => $w->whereIn('status', ['scheduled', 'sending']))
            ->when($request->query('tab') === 'history', fn ($w) => $w->whereIn('status', ['done', 'cancelled']))
            ->latest();

        return $q->paginate(min(1000, max(5, (int) $request->query('per_page', 10))));
    }

    public function show(Request $request, Broadcast $broadcast)
    {
        abort_unless($broadcast->user_id === $request->user()->id, 404);
        $recipients = $broadcast->recipients()
            ->when($request->query('status'), fn ($q, $s) => $s === 'sent' ? $q->whereIn('status', ['sent', 'delivered', 'read']) : $q->where('status', $s))
            ->orderBy('id')->paginate(20, ['id', 'name', 'phone', 'status', 'error', 'sent_at']);

        return ['broadcast' => Broadcast::withStats()->find($broadcast->id), 'recipients' => $recipients];
    }

    /** Who would receive it (deduplicated by phone). */
    private function audience(Request $request, array $a): array
    {
        $uid = $request->user()->id;
        $rows = [];
        if ($a['source'] === 'customers') {
            Customer::where('user_id', $uid)->whereNotNull('phone')
                ->when($a['label'] ?? null, fn ($q, $l) => $q->whereJsonContains('labels', $l))
                ->when($a['opt_in_only'] ?? true, fn ($q) => $q->where('opt_in', true))
                ->orderBy('id')->each(function ($c) use (&$rows) {
                    $rows[] = ['customer_id' => $c->id, 'name' => $c->name, 'phone' => $c->phone];
                });
        } else {
            foreach (preg_split('/\r?\n/', (string) ($a['numbers'] ?? '')) as $line) {
                $parts = array_map('trim', preg_split('/[,;\t]/', $line, 2));
                if ($phone = Customer::normalizePhone($parts[0] ?? '')) {
                    $rows[] = ['customer_id' => null, 'name' => ($parts[1] ?? '') ?: null, 'phone' => $phone];
                }
            }
        }
        $seen = [];

        return array_values(array_filter($rows, function ($r) use (&$seen) {
            $p = Customer::normalizePhone($r['phone']);
            if (! $p || strlen($p) < 9 || isset($seen[$p])) {
                return false;
            }

            return $seen[$p] = true;
        }));
    }

    private function audienceRules(): array
    {
        return [
            'audience' => 'required|array', 'audience.source' => 'required|in:customers,manual',
            'audience.label' => 'nullable|string|max:40', 'audience.opt_in_only' => 'boolean',
            'audience.numbers' => 'required_if:audience.source,manual|nullable|string|max:200000',
        ];
    }

    public function preview(Request $request)
    {
        $d = $request->validate($this->audienceRules());
        $rows = $this->audience($request, $d['audience']);

        return ['count' => count($rows), 'sample' => array_slice($rows, 0, 5)];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $d = $request->validate($this->audienceRules() + [
            'name' => 'required|string|max:120',
            'template' => 'required|array', 'template.name' => 'required|string|max:512', 'template.language' => 'required|string|max:15',
            'variables' => 'nullable|array|max:20', 'variables.*.mode' => 'required|in:text,name', 'variables.*.value' => 'nullable|string|max:300',
            'scheduled_at' => 'nullable|date|after:now|before:+60 days',
        ]);
        $i = $this->integration($user);
        abort_unless($i, 422, 'WhatsApp belum terhubung. Hubungkan di Pengaturan → Integrasi.');

        // The template must exist and be approved; its text is kept for previews and the chat history.
        try {
            $tpl = collect($this->client($request)->templates())->map([self::class, 'presentTemplate'])
                ->first(fn ($t) => $t['name'] === $d['template']['name'] && $t['language'] === $d['template']['language']);
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }
        abort_unless($tpl, 422, 'Template tidak ditemukan.');
        abort_unless($tpl['status'] === 'APPROVED', 422, 'Template belum disetujui Meta (status: '.$tpl['status'].').');
        abort_unless($tpl['supported'], 422, $tpl['unsupported_reason']);
        $vars = array_slice(array_values($d['variables'] ?? []), 0, $tpl['variables']);
        abort_if(count($vars) < $tpl['variables'], 422, "Template ini butuh {$tpl['variables']} variabel.");
        foreach ($vars as $n => $v) {
            abort_if($v['mode'] === 'text' && trim((string) ($v['value'] ?? '')) === '', 422, 'Isi variabel {{'.($n + 1).'}}.');
        }

        $rows = $this->audience($request, $d['audience']);
        abort_unless($rows, 422, 'Tidak ada penerima. Pastikan pelanggan punya nomor HP'.(($d['audience']['opt_in_only'] ?? true) && $d['audience']['source'] === 'customers' ? ' dan sudah setuju menerima promosi.' : '.'));
        $plan = Plans::current($user);
        $limit = Plans::all()[$plan]['limits']['broadcast'];
        $used = Plans::usage($user)['broadcast'];
        if ($limit !== null && $used + count($rows) > $limit) {
            abort(response()->json(['message' => 'Kuota broadcast paket '.Plans::all()[$plan]['name']." tersisa {$limit} − {$used} = ".max(0, $limit - $used).' penerima bulan ini. Kurangi penerima atau upgrade paket.', 'upgrade' => true, 'limit' => 'broadcast'], 402));
        }

        $b = DB::transaction(function () use ($user, $i, $d, $tpl, $vars, $rows, $request) {
            $b = new Broadcast([
                'name' => $d['name'], 'phone_number_id' => $i->settings['phone_number_id'], 'template_name' => $tpl['name'],
                'template_language' => $tpl['language'], 'template_category' => $tpl['category'], 'body' => $tpl['body'], 'variables' => $vars,
                'audience' => array_diff_key($d['audience'], ['numbers' => 1]) + ($d['audience']['source'] === 'manual' ? ['count' => count($rows)] : []),
                'status' => 'scheduled', 'scheduled_at' => $d['scheduled_at'] ?? now(), 'created_by' => Member::actorName($request),
            ]);
            $b->user_id = $user->id;
            $b->save();
            foreach (array_chunk($rows, 500) as $chunk) {
                BroadcastRecipient::insert(array_map(fn ($r) => $r + ['broadcast_id' => $b->id, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()], $chunk));
            }

            return $b;
        });

        return response()->json(['broadcast' => Broadcast::withStats()->find($b->id)], 201);
    }

    public function cancel(Request $request, Broadcast $broadcast)
    {
        abort_unless($broadcast->user_id === $request->user()->id, 404);
        abort_unless(in_array($broadcast->status, ['scheduled', 'sending'], true), 422, 'Broadcast ini sudah selesai.');
        $broadcast->recipients()->where('status', 'queued')->update(['status' => 'cancelled']);
        $broadcast->update(['status' => 'cancelled', 'finished_at' => now()]);

        return ['broadcast' => Broadcast::withStats()->find($broadcast->id)];
    }
}
