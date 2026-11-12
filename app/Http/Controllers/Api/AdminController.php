<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\BlogPost;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\SubscriptionInvoice;
use App\Models\Tutorial;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\Plans;
use App\Support\Subscriptions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Pesanin platform admin: business overview, sellers, billing, connected shops and site content. */
class AdminController extends Controller
{
    /** Monthly recurring revenue a user brings in right now (yearly plans spread over 12 months). */
    private static function mrrOf(User $u): int
    {
        if ($u->plan_billing === 'trial') {
            return 0; // free trial brings in nothing yet
        }
        $plan = Plans::current($u);
        $monthly = Plans::all()[$plan]['monthly'] ?? 0;

        return $u->plan_billing === 'yearly' ? (int) round($monthly * Plans::YEARLY_MONTHS_PAID / 12) : $monthly;
    }

    public function overview()
    {
        $users = User::where('is_admin', false)->get(['id', 'plan', 'plan_billing', 'plan_expires_at', 'created_at', 'suspended_at']);
        $byPlan = collect(Plans::all())->map(fn ($p, $id) => ['plan' => $id, 'name' => $p['name'], 'users' => 0, 'mrr' => 0])->all();
        foreach ($users as $u) {
            $plan = Plans::current($u);
            $byPlan[$plan]['users']++;
            $byPlan[$plan]['mrr'] += self::mrrOf($u);
        }
        $since = now()->subDays(29)->startOfDay();
        $signups = User::where('is_admin', false)->where('created_at', '>=', $since)->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');
        $revenue = SubscriptionInvoice::where('status', 'paid')->where('paid_at', '>=', $since)->selectRaw('DATE(paid_at) d, SUM(total) t')->groupBy('d')->pluck('t', 'd');
        $daily = [];
        for ($d = $since->copy(); $d <= now(); $d->addDay()) {
            $k = $d->toDateString();
            $daily[] = ['date' => $k, 'signups' => (int) ($signups[$k] ?? 0), 'revenue' => (int) ($revenue[$k] ?? 0)];
        }
        $orders = Order::where('is_abandoned', false)->where('created_at', '>=', $since);

        return [
            'users' => [
                'total' => $users->count(),
                'paying' => $users->filter(fn ($u) => Plans::current($u) !== 'gratis' && $u->plan_billing !== 'trial')->count(),
                'trial' => $users->filter(fn ($u) => Plans::status($u)['status'] === 'trial')->count(),
                'locked' => $users->filter(fn ($u) => Plans::status($u)['status'] === 'expired')->count(),
                'new_7d' => $users->where('created_at', '>=', now()->subDays(7))->count(),
                'new_30d' => $users->where('created_at', '>=', $since)->count(),
                'suspended' => $users->whereNotNull('suspended_at')->count(),
                'expiring_7d' => $users->filter(fn ($u) => $u->plan !== 'gratis' && $u->plan_expires_at?->between(now(), now()->addDays(7)))->count(),
            ],
            'mrr' => array_sum(array_column($byPlan, 'mrr')),
            'by_plan' => array_values($byPlan),
            'billing' => [
                'revenue_30d' => (int) SubscriptionInvoice::where('status', 'paid')->where('paid_at', '>=', $since)->sum('total'),
                'unpaid' => SubscriptionInvoice::where('status', 'unpaid')->count(),
                'unpaid_total' => (int) SubscriptionInvoice::where('status', 'unpaid')->sum('total'),
            ],
            'usage' => [
                'orders_30d' => (clone $orders)->count(),
                'gmv_30d' => (int) (clone $orders)->whereNotIn('status', ['cancelled'])->sum('total'),
                'shopee_orders_30d' => (clone $orders)->where('channel', 'shopee')->count(),
                'shops' => MarketplaceShop::where('status', '!=', 'disconnected')->where('is_demo', false)->count(),
                // Expired authorisation or failing sync: the seller needs to reconnect.
                'shops_error' => MarketplaceShop::where('status', '!=', 'disconnected')->where('is_demo', false)
                    ->where(fn ($q) => $q->whereNotNull('last_error')->orWhere('status', 'expired'))->count(),
                'whatsapp' => UserIntegration::where('provider', 'whatsapp_cloud')->where('status', 'active')->count(),
            ],
            'daily' => $daily,
            'recent_users' => User::where('is_admin', false)->latest()->limit(6)->get(['id', 'name', 'email', 'store_name', 'plan', 'created_at']),
            'recent_payments' => SubscriptionInvoice::with('user:id,name,store_name')->where('status', 'paid')->latest('paid_at')->limit(6)
                ->get(['id', 'user_id', 'number', 'plan', 'billing', 'total', 'paid_at']),
        ];
    }

    public function users(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:100', 'plan' => 'nullable|string|max:20', 'status' => 'nullable|in:active,suspended,admin,expiring', 'per_page' => 'nullable|integer|min:1|max:1000']);
        $q = User::query()->withCount(['orders', 'marketplaceShops', 'products'])
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('store_name', 'like', "%{$s}%")->orWhere('whatsapp', 'like', "%{$s}%")))
            ->when(($data['plan'] ?? null) === 'gratis', fn ($q) => $q->where(fn ($w) => $w->where('plan', 'gratis')->orWhereNull('plan')->orWhere('plan_expires_at', '<', now())))
            ->when(($data['plan'] ?? null) && $data['plan'] !== 'gratis', fn ($q) => $q->where('plan', $data['plan'])->where(fn ($w) => $w->whereNull('plan_expires_at')->orWhere('plan_expires_at', '>=', now())))
            ->when(($data['status'] ?? null) === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when(($data['status'] ?? null) === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->when(($data['status'] ?? null) === 'admin', fn ($q) => $q->where('is_admin', true))
            ->when(($data['status'] ?? null) === 'expiring', fn ($q) => $q->where('plan', '!=', 'gratis')->whereBetween('plan_expires_at', [now(), now()->addDays(7)]))
            ->latest();
        $page = $q->paginate(min(1000, (int) ($data['per_page'] ?? 10)));
        $page->getCollection()->transform(fn (User $u) => $this->userRow($u));

        return $page;
    }

    private function userRow(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'store_name' => $u->store_name, 'whatsapp' => $u->whatsapp,
            'plan' => $u->plan, 'active_plan' => Plans::current($u), 'plan_billing' => $u->plan_billing, 'plan_expires_at' => $u->plan_expires_at,
            'subscription' => Plans::status($u),
            'is_admin' => $u->is_admin, 'suspended_at' => $u->suspended_at, 'created_at' => $u->created_at, 'mrr' => self::mrrOf($u),
            'orders_count' => $u->orders_count ?? null, 'shops_count' => $u->marketplace_shops_count ?? null, 'products_count' => $u->products_count ?? null,
        ];
    }

    public function user(User $user)
    {
        $user->loadCount(['orders', 'marketplaceShops', 'products']);

        return $this->userRow($user) + [
            'orders_30d' => $user->orders()->where('created_at', '>=', now()->subDays(30))->count(),
            'gmv_30d' => (int) $user->orders()->where('created_at', '>=', now()->subDays(30))->whereNotIn('status', ['cancelled'])->sum('total'),
            'shops' => $user->marketplaceShops()->get(['id', 'shop_name', 'shop_id', 'status', 'is_demo', 'last_error', 'orders_synced_at']),
            'integrations' => UserIntegration::where('user_id', $user->id)->get(['provider', 'status', 'updated_at']),
            'invoices' => $user->subscriptionInvoices()->latest()->limit(20)->get(['id', 'number', 'plan', 'billing', 'total', 'status', 'method', 'created_at', 'paid_at', 'period_end']),
            'activity' => ActivityLog::where('user_id', $user->id)->latest('created_at')->limit(15)->get(['action', 'description', 'created_at']),
        ];
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'plan' => ['sometimes', Rule::in(array_keys(Plans::all()))],
            'plan_billing' => 'sometimes|nullable|in:monthly,yearly,trial',
            'plan_expires_at' => 'sometimes|nullable|date',
            'suspended' => 'sometimes|boolean',
            'is_admin' => 'sometimes|boolean',
        ]);
        if ($user->is($request->user())) {
            abort_if(($data['suspended'] ?? false) || (array_key_exists('is_admin', $data) && ! $data['is_admin']), 422, 'Tidak bisa menonaktifkan atau mencabut admin akun sendiri.');
        }
        $changes = [];
        foreach (['plan', 'plan_billing'] as $k) {
            if (array_key_exists($k, $data)) {
                $changes[$k] = $data[$k];
            }
        }
        if (array_key_exists('plan_expires_at', $data)) {
            $changes['plan_expires_at'] = $data['plan_expires_at'] ? Carbon::parse($data['plan_expires_at'])->endOfDay() : null;
        }
        if (array_key_exists('suspended', $data)) {
            $changes['suspended_at'] = $data['suspended'] ? ($user->suspended_at ?? now()) : null;
        }
        if (array_key_exists('is_admin', $data)) {
            $changes['is_admin'] = $data['is_admin'];
        }
        $user->forceFill($changes)->save();
        if (! empty($data['suspended'])) {
            $user->tokens()->delete();
        }
        ActivityLog::record($user, 'admin.update', 'Diubah oleh admin '.$request->user()->email.': '.implode(', ', array_keys($changes)));

        return $this->user($user->fresh());
    }

    public function invoices(Request $request)
    {
        $data = $request->validate(['status' => 'nullable|in:unpaid,paid,cancelled,expired', 'search' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:1000']);

        return SubscriptionInvoice::with('user:id,name,email,store_name')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$s}%")->orWhere('store_name', 'like', "%{$s}%"))))
            ->latest()->paginate(min(1000, (int) ($data['per_page'] ?? 10)));
    }

    /** Manual confirmation, e.g. a seller paid by bank transfer outside the gateway. */
    public function markInvoicePaid(Request $request, SubscriptionInvoice $invoice)
    {
        abort_unless(in_array($invoice->status, ['unpaid', 'expired'], true), 422, 'Tagihan ini tidak menunggu pembayaran.');
        Subscriptions::markPaid($invoice, 'dikonfirmasi admin '.$request->user()->email);

        return $invoice->fresh('user:id,name,email,store_name');
    }

    public function shops(Request $request)
    {
        $data = $request->validate(['status' => 'nullable|in:active,disconnected,error', 'search' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:1000']);

        return MarketplaceShop::with('user:id,name,email,store_name')->where('is_demo', false)->withCount('orders')
            ->when(($data['status'] ?? null) === 'error', fn ($q) => $q->where('status', '!=', 'disconnected')->where(fn ($w) => $w->whereNotNull('last_error')->orWhere('status', 'expired')))
            ->when(in_array($data['status'] ?? null, ['active', 'disconnected'], true), fn ($q) => $q->where('status', $data['status']))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('shop_name', 'like', "%{$s}%")->orWhere('shop_id', 'like', "%{$s}%")))
            ->latest()
            ->paginate(min(1000, (int) ($data['per_page'] ?? 10)), ['id', 'user_id', 'platform', 'shop_id', 'shop_name', 'status', 'last_error', 'orders_synced_at', 'products_synced_at', 'refresh_expires_at', 'created_at']);
    }

    /* ── Platform: plans, feature gates, registration, notice, contact ─────────── */

    public const SITE_DEFAULTS = ['registration_open' => true, 'notice' => null, 'notice_tone' => 'info', 'whatsapp' => '6281234567890', 'email' => 'halo@pesaninn.my.id'];

    public function platform()
    {
        return [
            'plans' => collect(Plans::all())->map(fn ($p, $id) => ['id' => $id, 'name' => $p['name'], 'monthly' => $p['monthly'], 'limits' => $p['limits'],
                'default' => ['name' => Plans::PLANS[$id]['name'], 'monthly' => Plans::PLANS[$id]['monthly'], 'limits' => Plans::PLANS[$id]['limits']]])->values(),
            'limit_labels' => collect(array_keys(Plans::PLANS['gratis']['limits']))->mapWithKeys(fn ($k) => [$k => Plans::LABELS[$k]]),
            'features' => collect(Plans::features())->map(fn ($need, $f) => ['key' => $f, 'label' => Plans::LABELS[$f], 'plan' => $need, 'default' => Plans::FEATURES[$f]])->values(),
            'site' => array_merge(self::SITE_DEFAULTS, \App\Models\PlatformSetting::read('site', [])),
        ];
    }

    public function updatePlatform(Request $request)
    {
        $limitKeys = array_keys(Plans::PLANS['gratis']['limits']);
        $data = $request->validate([
            'plans' => 'sometimes|array', 'plans.*.name' => 'required|string|max:30', 'plans.*.monthly' => 'required|integer|min:0|max:100000000',
            'plans.*.limits' => 'required|array', 'plans.*.limits.*' => 'nullable|integer|min:0|max:100000000',
            'features' => 'sometimes|array', 'features.*' => ['required', Rule::in(array_keys(Plans::PLANS))],
            'site' => 'sometimes|array', 'site.registration_open' => 'boolean', 'site.notice' => 'nullable|string|max:300',
            'site.notice_tone' => 'in:info,warning,success', 'site.whatsapp' => ['nullable', 'regex:/^62\d{8,14}$/'], 'site.email' => 'nullable|email',
        ]);
        if (isset($data['plans'])) {
            $plans = [];
            foreach (array_intersect_key($data['plans'], Plans::PLANS) as $id => $p) {
                $plans[$id] = ['name' => $p['name'], 'monthly' => $id === 'gratis' ? 0 : $p['monthly'],
                    'limits' => array_intersect_key($p['limits'], array_flip($limitKeys))];
            }
            \App\Models\PlatformSetting::write('plans', $plans);
        }
        if (isset($data['features'])) {
            \App\Models\PlatformSetting::write('features', array_intersect_key($data['features'], Plans::FEATURES));
        }
        if (isset($data['site'])) {
            \App\Models\PlatformSetting::write('site', array_intersect_key(array_merge(self::SITE_DEFAULTS, \App\Models\PlatformSetting::read('site', []), $data['site']), self::SITE_DEFAULTS));
        }
        ActivityLog::record($request->user(), 'admin.platform', 'Mengubah pengaturan platform: '.implode(', ', array_keys($data)));

        return $this->platform();
    }

    /* ── Site content: tutorials, blog posts, announcements ───────────────────── */

    private const CONTENT = [
        'tutorials' => Tutorial::class,
        'blog-posts' => BlogPost::class,
        'announcements' => Announcement::class,
    ];

    private function contentRules(string $type, ?int $id = null): array
    {
        return match ($type) {
            'tutorials' => [
                'title' => 'required|string|max:140', 'slug' => ['nullable', 'alpha_dash', 'max:140', Rule::unique('tutorials')->ignore($id)],
                'category' => 'required|in:pemula,analitik,lanjutan', 'description' => 'required|string|max:300',
                'video_url' => ['nullable', 'url', 'max:255', 'regex:#(youtube\.com|youtu\.be)/#'], 'duration' => ['nullable', 'regex:/^\d{1,2}:\d{2}$/'],
                'steps' => 'nullable|array|max:30', 'steps.*' => 'nullable|string|max:400', 'is_published' => 'boolean', 'sort' => 'nullable|integer|min:0',
            ],
            'blog-posts' => [
                'title' => 'required|string|max:160', 'slug' => ['nullable', 'alpha_dash', 'max:160', Rule::unique('blog_posts')->ignore($id)],
                'category' => 'required|string|max:40', 'excerpt' => 'required|string|max:300', 'body' => 'required|string|max:60000',
                'cover_url' => 'nullable|url|max:255', 'is_published' => 'boolean', 'published_at' => 'nullable|date',
            ],
            'announcements' => [
                'tag' => 'required|in:NEW,UPDATE,INFO', 'title' => 'required|string|max:120', 'body' => 'required|string|max:2000',
                'is_published' => 'boolean', 'published_at' => 'nullable|date',
            ],
        };
    }

    public function contentIndex(Request $request, string $type)
    {
        $model = self::CONTENT[$type] ?? abort(404);
        $order = ['tutorials' => 'sort', 'blog-posts' => 'published_at', 'announcements' => 'published_at'][$type];

        return $model::query()->when($request->query('search'), fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($request->query('status') === 'published', fn ($q) => $q->where('is_published', true)->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now())))
            ->when($request->query('status') === 'scheduled', fn ($q) => $q->where('is_published', true)->where('published_at', '>', now()))
            ->when($request->query('status') === 'draft', fn ($q) => $q->where('is_published', false))
            ->when($type === 'blog-posts' && $request->query('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->orderBy($order, $type === 'tutorials' ? 'asc' : 'desc')->orderByDesc('id')
            ->paginate(min(1000, max(1, $request->integer('per_page', 10))), $type === 'blog-posts' ? ['id', 'slug', 'title', 'category', 'excerpt', 'cover_url', 'is_published', 'published_at', 'views', 'updated_at'] : ['*']);
    }

    public function contentShow(string $type, int $id)
    {
        $model = self::CONTENT[$type] ?? abort(404);

        return $model::findOrFail($id);
    }

    /** Categories already used by blog posts, for the editor's picker. */
    public function blogCategories()
    {
        return BlogPost::query()->selectRaw('category, COUNT(*) n')->groupBy('category')->orderByDesc('n')->pluck('n', 'category');
    }

    /** Images for blog covers and article bodies (public disk). */
    public function upload(Request $request)
    {
        $request->validate(['file' => 'required|image|max:4096']);

        return ['url' => asset('storage/'.$request->file('file')->store('blog', 'public'))];
    }

    public function contentStore(Request $request, string $type)
    {
        $model = self::CONTENT[$type] ?? abort(404);

        return response()->json($model::create($this->contentData($request, $type)), 201);
    }

    public function contentUpdate(Request $request, string $type, int $id)
    {
        $model = self::CONTENT[$type] ?? abort(404);
        $row = $model::findOrFail($id);
        $row->update($this->contentData($request, $type, $id));

        return $row;
    }

    public function contentDestroy(string $type, int $id)
    {
        $model = self::CONTENT[$type] ?? abort(404);
        $model::findOrFail($id)->delete();

        return response()->noContent();
    }

    private function contentData(Request $request, string $type, ?int $id = null): array
    {
        $data = $request->validate($this->contentRules($type, $id));
        if (isset($data['title']) && $type !== 'announcements' && empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']).($id ? '' : '-'.Str::lower(Str::random(4)));
        }
        if ($type !== 'tutorials' && ($data['is_published'] ?? true) && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        if ($type === 'tutorials') {
            $data['steps'] = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $data['steps'] ?? [])));
        }

        return $data;
    }
}
