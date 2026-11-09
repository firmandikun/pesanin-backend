<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\CsStaff;
use App\Models\LandingPage;
use App\Models\Order;
use App\Models\PaymentConfirmation;
use App\Models\User;
use App\Models\WaRotator;
use App\Support\WhatsApp;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeatureController extends Controller
{
    private function range(Request $request, int $days = 6): array
    {
        $to = Carbon::parse($request->query('to', today()->toDateString()))->endOfDay();
        $from = Carbon::parse($request->query('from', $to->copy()->subDays($days)->toDateString()))->startOfDay();

        return [$from, $to];
    }

    /** Settings > Balance: money flow derived from orders (COD and transfer). */
    public function balance(Request $request)
    {
        [$from, $to] = $this->range($request, 0);
        $orders = $request->user()->orders()->active();
        $paid = (clone $orders)->where('payment_status', 'paid');
        $cod = (clone $paid)->where('payment_method', 'cod');
        $inRange = fn ($q) => $q->whereBetween('paid_at', [$from, $to]);

        $available = (int) (clone $paid)->sum('total') - (int) (clone $paid)->sum('cod_fee');
        $pending = (int) (clone $orders)->where('payment_method', 'cod')->where('payment_status', 'unpaid')->where('shipping_status', '!=', 'not_shipped')->sum('total');

        return [
            'available' => $available,
            'withdrawing' => 0,
            'pending' => $pending,
            'period' => [
                'net_cod_revenue' => (int) $inRange(clone $cod)->sum(DB::raw('total - cod_fee - shipping_cost')),
                'collected_from_customer' => (int) $inRange(clone $cod)->sum('total'),
                'cod_shipping_fees' => (int) $inRange(clone $cod)->sum(DB::raw('shipping_cost + cod_fee')),
                'net_shipping_saved' => 0, 'shipping_saved' => 0,
                'return_shipping' => (int) $inRange((clone $orders)->where('shipping_status', 'returned'))->sum('shipping_cost'),
                'withdrawn' => 0,
                'cod_income' => (int) $inRange(clone $cod)->sum('total'),
                'topup' => 0, 'invoices_paid' => 0,
                'refunds' => (int) $inRange((clone $orders)->where('status', 'refunded'))->sum('total'),
                'claims' => 0,
            ],
            'mutations' => $inRange(clone $paid)->with('product:id,name')->latest('paid_at')->limit(50)->get()->map(fn (Order $o) => [
                'date' => $o->paid_at?->toDateTimeString(), 'courier' => $o->courier, 'type' => $o->payment_method === 'cod' ? 'COD' : 'Transfer',
                'description' => "Pembayaran {$o->code} — {$o->product?->name}", 'amount' => $o->total, 'status' => $o->status, 'invoice' => $o->code,
            ]),
        ];
    }

    /** Landing Page > Laporan: sessions, users, bounce, referrers from tracked visits. */
    public function landingReports(Request $request)
    {
        [$from, $to] = $this->range($request);
        $user = $request->user();
        $pageIds = $user->landingPages()->when($request->query('page_id'), fn ($q, $id) => $q->whereKey($id))->pluck('id');
        $visits = DB::table('landing_page_visits')->whereIn('landing_page_id', $pageIds)->whereBetween('created_at', [$from, $to]);
        $prevSpan = $from->diffInSeconds($to);
        $prev = DB::table('landing_page_visits')->whereIn('landing_page_id', $pageIds)->whereBetween('created_at', [$from->copy()->subSeconds($prevSpan), $from]);

        $agg = fn ($q) => (clone $q)->selectRaw('COUNT(*) sessions, COUNT(DISTINCT visitor_id) users, SUM(is_new) new_sessions,
            AVG(duration) avg_duration, SUM(clicks = 0) no_click, SUM(max_scroll < 50) low_scroll, SUM(clicks) clicks')->first();
        $cur = $agg($visits);
        $old = $agg($prev);
        $s = (int) $cur->sessions;
        $growth = fn ($a, $b) => $b ? round(($a - $b) / $b * 100) : ($a ? 100 : 0);

        $daily = collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()))->map(function (Carbon $d) use ($visits) {
            $r = (clone $visits)->whereDate('created_at', $d)->selectRaw('COUNT(*) s, COUNT(DISTINCT visitor_id) u, SUM(clicks) c')->first();

            return ['date' => $d->toDateString(), 'sessions' => (int) $r->s, 'visitors' => (int) $r->u, 'clicks' => (int) $r->c];
        })->values();

        $group = fn (string $col) => (clone $visits)->selectRaw("COALESCE($col, '(direct)') label, COUNT(DISTINCT visitor_id) users, SUM(is_new) new_users,
            COUNT(*) sessions, AVG(clicks = 0) * 100 bounce, AVG(duration) avg_session")->groupBy('label')->orderByDesc('sessions')->limit(50)->get();
        $counts = $user->landingPages()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return [
            'pages' => ['total' => (int) $counts->sum(), 'draft' => (int) ($counts['draft'] ?? 0), 'published' => (int) ($counts['published'] ?? 0), 'archived' => (int) ($counts['archived'] ?? 0)],
            'sessions' => [
                'sessions' => $s, 'sessions_growth' => $growth($s, (int) $old->sessions),
                'users' => (int) $cur->users, 'users_growth' => $growth((int) $cur->users, (int) $old->users),
                'page_per_session' => $s ? round($pageIds->count() ? (int) $cur->clicks / $s : 0, 2) : 0,
                'avg_duration' => (int) $cur->avg_duration,
                'bounce_click' => $s ? round($cur->no_click / $s * 100, 2) : 0,
                'bounce_scroll' => $s ? round($cur->low_scroll / $s * 100, 2) : 0,
                'new_sessions' => $s ? round($cur->new_sessions / $s * 100, 2) : 0,
            ],
            'daily' => $daily,
            'overview' => ['opens' => $s, 'visitors' => (int) $cur->users, 'clicks' => (int) $cur->clicks],
            'referrers' => $group('referrer_domain'),
            'urls' => $group('referrer_url'),
            'page_list' => $user->landingPages()->get(['id', 'title']),
        ];
    }

    /** Beacon from public landing pages (view / click / scroll / ping). */
    public function track(Request $request, string $slug)
    {
        $data = $request->validate([
            'visitor_id' => 'required|string|max:40', 'session_id' => 'required|string|max:40',
            'event' => 'required|in:view,click,scroll,ping', 'referrer' => 'nullable|string|max:500',
            'scroll' => 'nullable|integer|min:0|max:100', 'duration' => 'nullable|integer|min:0|max:86400',
        ]);
        $page = LandingPage::where('slug', $slug)->where('status', 'published')->firstOrFail();
        $visit = DB::table('landing_page_visits')->where(['landing_page_id' => $page->id, 'session_id' => $data['session_id']])->first();

        if (! $visit) {
            $ref = $data['referrer'] ?? null;
            DB::table('landing_page_visits')->insert([
                'landing_page_id' => $page->id, 'visitor_id' => $data['visitor_id'], 'session_id' => $data['session_id'],
                'referrer_domain' => $ref ? parse_url($ref, PHP_URL_HOST) : null, 'referrer_url' => $ref ? Str::limit($ref, 490, '') : null,
                'is_new' => ! DB::table('landing_page_visits')->where('visitor_id', $data['visitor_id'])->exists(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return response()->noContent();
        }
        $patch = ['updated_at' => now()];
        if ($data['event'] === 'click') {
            $patch['clicks'] = $visit->clicks + 1;
        }
        if (isset($data['scroll'])) {
            $patch['max_scroll'] = max($visit->max_scroll, $data['scroll']);
        }
        if (isset($data['duration'])) {
            $patch['duration'] = max($visit->duration, $data['duration']);
        }
        DB::table('landing_page_visits')->where('id', $visit->id)->update($patch);

        return response()->noContent();
    }

    /** Lainnya > Stok */
    public function inventory(Request $request)
    {
        $user = $request->user();
        $committed = $user->orders()->active()->whereIn('status', ['pending', 'processing'])->where('shipping_status', 'not_shipped')
            ->selectRaw("product_id, COALESCE(variant, '') v, SUM(quantity) q")->groupBy('product_id', 'v')->get()
            ->groupBy('product_id');
        $rows = [];
        foreach ($user->products()->where('type', 'physical')->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))->get() as $p) {
            $c = $committed->get($p->id, collect());
            if ($p->variants) {
                foreach ($p->variants as $v) {
                    $rows[] = ['product' => $p->name, 'variant' => $v['name'], 'image' => $v['image'] ?? ($p->images[0] ?? null), 'sku' => $p->sku, 'tracked' => $p->track_stock,
                        'incoming' => 0, 'committed' => (int) ($c->firstWhere('v', $v['name'])->q ?? 0), 'available' => $p->track_stock ? (int) ($v['stock'] ?? 0) : null];
                }
            } else {
                $rows[] = ['product' => $p->name, 'variant' => null, 'image' => $p->images[0] ?? null, 'sku' => $p->sku, 'tracked' => $p->track_stock,
                    'incoming' => 0, 'committed' => (int) $c->sum('q'), 'available' => $p->track_stock ? (int) $p->stock : null];
            }
        }
        if ($request->query('filter') === 'tracked') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['tracked']));
        }

        return $rows;
    }

    /** Lainnya > Laporan Produk: team performance (owner + team members). */
    public function productReports(Request $request)
    {
        [$from, $to] = $this->range($request);
        $user = $request->user();
        $q = $user->orders()->active()->whereBetween('created_at', [$from, $to])->when($request->query('product_id'), fn ($qq, $id) => $qq->where('product_id', $id));
        $r = (clone $q)->selectRaw("COUNT(*) orders, SUM(payment_status='paid') paid, SUM(quantity) qty, SUM(payment_status='unpaid') unpaid, SUM(total) gross")->first();
        $owner = ['name' => $user->name, 'orders' => (int) $r->orders, 'paid' => (int) $r->paid, 'paid_ratio' => $r->orders ? round($r->paid / $r->orders * 100, 1) : 0,
            'quantity' => (int) $r->qty, 'unpaid' => (int) $r->unpaid, 'gross' => (int) $r->gross];
        $members = DB::table('team_members')->where('user_id', $user->id)->get()->map(fn ($m) => ['name' => $m->name, 'orders' => 0, 'paid' => 0, 'paid_ratio' => 0, 'quantity' => 0, 'unpaid' => 0, 'gross' => 0]);

        return ['members' => collect([$owner])->concat($members)->values(), 'products' => $user->products()->get(['id', 'name'])];
    }

    /** Lainnya > Transaksi: e-payment orders. */
    public function transactions(Request $request)
    {
        [$from, $to] = $this->range($request, 183);
        $q = $request->user()->orders()->active()->where('payment_method', 'epayment')->whereBetween('created_at', [$from, $to]);
        $stat = fn ($qq) => ['count' => (clone $qq)->count(), 'total' => (int) (clone $qq)->sum('total')];

        return [
            'failed' => $stat((clone $q)->whereIn('status', ['cancelled'])),
            'success' => $stat((clone $q)->where('payment_status', 'paid')),
            'settled' => $stat((clone $q)->where('payment_status', 'paid')->where('status', 'completed')),
            'rows' => (clone $q)->with('product:id,name')->when($request->query('search'), fn ($qq, $s) => $qq->where('code', 'like', "%{$s}%"))->latest()->paginate(10),
        ];
    }

    public function affiliateStats(Request $request)
    {
        $user = $request->user();

        return Affiliate::where('user_id', $user->id)->latest()->get()->map(function (Affiliate $a) use ($user) {
            $orders = $user->orders()->active()->where('affiliate_id', $a->id);
            $paid = (int) (clone $orders)->where('payment_status', 'paid')->sum('total');

            return $a->toArray() + ['orders' => (clone $orders)->count(), 'revenue' => $paid, 'commission' => (int) round($paid * $a->commission_percent / 100)];
        });
    }

    /** WA Rotator list stats (unique views, today, returning today). */
    public function rotatorStats(Request $request)
    {
        [$from, $to] = $this->range($request, 183);

        return WaRotator::where('user_id', $request->user()->id)->latest()->get()->map(function (WaRotator $r) use ($from, $to) {
            $hits = DB::table('wa_rotator_hits')->where('wa_rotator_id', $r->id);

            return $r->toArray() + [
                'uv' => (clone $hits)->whereBetween('created_at', [$from, $to])->distinct('visitor_id')->count('visitor_id'),
                'uv_today' => (clone $hits)->whereDate('created_at', today())->distinct('visitor_id')->count('visitor_id'),
                'returning_today' => (clone $hits)->whereDate('created_at', today())->where('is_returning', true)->count(),
            ];
        });
    }

    public function rotatorChats(Request $request)
    {
        return DB::table('wa_rotator_hits as h')->join('wa_rotators as r', 'r.id', '=', 'h.wa_rotator_id')->leftJoin('cs_staff as s', 's.id', '=', 'h.cs_staff_id')
            ->where('r.user_id', $request->user()->id)->when($request->query('search'), fn ($q, $s) => $q->where('h.chat_id', 'like', "%{$s}%"))
            ->orderByDesc('h.id')->select('h.id', 'r.title as rotator', 's.name as staff', 'h.chat_id', 'h.created_at')->paginate($request->integer('per_page', 10));
    }

    /** Public rotator link: pick the next available CS (round robin, respecting shifts & time off). */
    public function rotatorPublic(Request $request, string $slug)
    {
        $r = WaRotator::where('slug', $slug)->firstOrFail();
        $owner = User::find($r->user_id);
        $global = $owner->settings['rotator'] ?? [];
        $cfg = array_merge($global, array_filter($r->settings ?? [], fn ($v) => $v !== null && $v !== ''));
        $day = strtolower(now()->englishDayOfWeek);
        $time = now()->format('H:i');

        $staff = CsStaff::whereIn('id', $r->staff_ids ?? [])->where('is_active', true)->get()->filter(function (CsStaff $s) use ($day, $time) {
            $off = DB::table('staff_time_offs')->where('cs_staff_id', $s->id)->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->exists();
            $shift = $s->schedule[$day] ?? null;
            $onShift = ! $s->schedule || ($shift && ! empty($shift['on']) && $time >= ($shift['start'] ?? '00:00') && $time <= ($shift['end'] ?? '23:59'));

            return ! $off && $onShift;
        })->values();

        $visitor = Str::limit((string) $request->query('visitor', Str::random(20)), 40, '');
        $chosen = $staff->count() ? $staff[$r->cursor % $staff->count()] : null;
        $chatId = strtoupper(Str::random(6));
        if ($chosen) {
            $r->increment('cursor');
            DB::table('wa_rotator_hits')->insert([
                'wa_rotator_id' => $r->id, 'cs_staff_id' => $chosen->id, 'visitor_id' => $visitor, 'chat_id' => $chatId,
                'is_returning' => DB::table('wa_rotator_hits')->where(['wa_rotator_id' => $r->id, 'visitor_id' => $visitor])->exists(), 'created_at' => now(),
            ]);
        }
        $greeting = strtr($cfg['greeting'] ?? 'Hi :nama_cs:, saya suka produk ini nih, boleh tanya-tanya dulu gak ya?', [
            ':nama_cs:' => $chosen?->name, ':judul_rotator:' => $r->title, ':nama_produk:' => $r->product_id ? \App\Models\Product::find($r->product_id)?->name : '',
        ])." (ID: {$chatId})";
        foreach ($cfg['dynamic'] ?? [] as $param) {
            if (! empty($param['key'])) {
                $greeting = str_replace(":{$param['key']}:", (string) $request->query($param['key'], $param['value'] ?? ''), $greeting);
            }
        }
        $phone = WhatsApp::normalize($chosen?->phone ?? ($cfg['fallback_phone'] ?? $owner->whatsapp));
        $url = ($cfg['method'] ?? 'api') === 'deeplink'
            ? 'whatsapp://send?phone='.$phone.'&text='.rawurlencode($greeting)
            : 'https://api.whatsapp.com/send?phone='.$phone.'&text='.rawurlencode($greeting);

        return [
            'available' => (bool) $chosen,
            'url' => $chosen || ! empty($cfg['fallback_phone']) || $owner->whatsapp ? $url : null,
            'delay' => (int) ($cfg['delay'] ?? 0),
            'texts' => [
                'redirect' => $cfg['text_redirect'] ?? 'Tunggu sebentar, Anda akan segera terhubung dengan customer service kami.',
                'unavailable' => $cfg['text_unavailable'] ?? 'Mohon maaf, semua customer service kami sedang tidak ada di tempat.',
                'button' => $cfg['text_button'] ?? 'Chat Lewat WhatsApp',
            ],
            'loading' => ['color' => $cfg['loading_color'] ?? '#e53935', 'type' => $cfg['loading_type'] ?? 'spinner'],
        ];
    }

    /** Sample courier list for the product editor preview (pickup address -> a typical destination). */
    public function shippingPreview(Request $request)
    {
        $origin = $request->user()->pickupAddress()?->region;
        if (! $origin) {
            return ['origin' => null, 'rates' => []];
        }
        $dest = \App\Models\Region::where('city', $origin->city === 'JAKARTA SELATAN' ? 'BANDUNG' : 'JAKARTA SELATAN')->first() ?? $origin;
        $product = new \App\Models\Product(['form_settings' => ['shipping' => (array) $request->input('shipping', [])]]);
        $weight = max(1, (int) $request->input('weight', 1000));

        return ['origin' => $origin->city, 'destination' => $dest->label, 'rates' => \App\Support\CheckoutService::adjustRates($product, \App\Support\ShippingRates::rates($origin, $dest, $weight))];
    }

    public function userSettings(Request $request)
    {
        return $request->user()->settings ?? [];
    }

    public function updateUserSettings(Request $request)
    {
        $data = $request->validate(['key' => 'required|in:rotator,confirm_page,affiliate,whatsapp_devices,notify,followup,automation', 'value' => 'present|array']);
        $user = $request->user();
        $user->update(['settings' => array_merge($user->settings ?? [], [$data['key'] => $data['value']])]);

        return $user->settings;
    }

    /** Public "Konfirmasi Pembayaran" page for buyers. */
    public function confirmPage(string $store)
    {
        $user = User::where('store_slug', $store)->firstOrFail();

        return ['store_name' => $user->store_name, 'page' => $user->settings['confirm_page'] ?? [], 'banks' => $user->bank_accounts ?? []];
    }

    public function confirmSubmit(Request $request, string $store)
    {
        $user = User::where('store_slug', $store)->firstOrFail();
        $data = $request->validate([
            'order_code' => 'required|string|max:40', 'account_name' => 'required|string|max:100', 'bank' => 'nullable|string|max:60',
            'amount' => 'required|integer|min:1', 'transfer_date' => 'required|date', 'proof' => 'nullable|image|max:4096',
        ]);
        $order = $user->orders()->where('code', strtoupper(trim($data['order_code'])))->first();
        $conf = new PaymentConfirmation(collect($data)->except('proof')->all() + ['order_id' => $order?->id]);
        if ($request->hasFile('proof')) {
            $conf->proof = asset('storage/'.$request->file('proof')->store('proofs', 'public'));
        }
        $conf->user_id = $user->id;
        $conf->save();

        return response()->json(['ok' => true, 'order_found' => (bool) $order], 201);
    }
}
