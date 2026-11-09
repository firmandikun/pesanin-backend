<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdSpend;
use App\Models\Affiliate;
use App\Models\Coupon;
use App\Models\CsStaff;
use App\Models\CustomDomain;
use App\Models\NotificationAccount;
use App\Models\PaymentConfirmation;
use App\Models\StaffTimeOff;
use App\Models\TeamMember;
use App\Models\TrackingPixel;
use App\Models\WaRotator;
use App\Support\Plans;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** CRUD for the seller's simple settings resources, scoped to the signed-in user. */
class ResourceController extends Controller
{
    private function config(Request $request, string $type): array
    {
        $uid = $request->user()->id;
        $own = fn (string $table) => Rule::exists($table, 'id')->where('user_id', $uid);

        return match ($type) {
            'domains' => [CustomDomain::class, ['domain' => ['required', 'string', 'max:120', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i']], 'domain'],
            'pixels' => [TrackingPixel::class, [
                'platform' => 'required|in:facebook,tiktok,google_ads,gtm,snack',
                'pixel_id' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
                'name' => 'nullable|string|max:80', 'server_side' => 'boolean',
            ], 'pixel_id'],
            'notification-accounts' => [NotificationAccount::class, [
                'type' => 'required|in:email,telegram', 'name' => 'required|string|max:80',
                'value' => 'required|string|max:120', 'is_active' => 'boolean', 'events' => 'nullable|array',
            ], 'name'],
            'staff' => [CsStaff::class, [
                'name' => 'required|string|max:80', 'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s-]{8,20}$/'],
                'is_active' => 'boolean', 'schedule' => 'nullable|array',
            ], 'name'],
            'time-offs' => [StaffTimeOff::class, [
                'cs_staff_id' => ['required', $own('cs_staff')], 'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date', 'note' => 'nullable|string|max:200',
            ], 'note'],
            'rotators' => [WaRotator::class, [
                'title' => 'required|string|max:120', 'product_id' => ['nullable', $own('products')],
                'staff_ids' => 'nullable|array', 'staff_ids.*' => [$own('cs_staff')], 'settings' => 'nullable|array',
            ], 'title'],
            'team' => [TeamMember::class, [
                'name' => 'required|string|max:80', 'phone' => 'nullable|string|max:30',
                // Email is the member's login: unique across members and never an owner account's email.
                'email' => ['nullable', 'email', 'max:120', 'required_with:password', Rule::unique('team_members', 'email')->ignore($request->route('id')), Rule::unique('users', 'email')],
                'password' => 'nullable|string|min:8|max:100',
                'role' => 'required|in:admin,cs,advertiser,gudang', 'accept_orders' => 'boolean', 'landing_access' => 'boolean', 'is_active' => 'boolean',
            ], 'name'],
            'affiliates' => [Affiliate::class, [
                'name' => 'required|string|max:80', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email',
                'code' => ['nullable', 'alpha_dash', 'max:40'], 'commission_percent' => 'required|numeric|min:0|max:100', 'is_active' => 'boolean',
            ], 'name'],
            'coupons' => [Coupon::class, [
                'kind' => 'required|in:product,order,shipping', 'method' => 'required|in:code,auto',
                'code' => ['nullable', 'required_if:method,code', 'alpha_dash', 'max:40'], 'product_type' => 'required|in:physical,digital',
                'product_ids' => 'nullable|array', 'value_type' => 'required|in:percent,amount', 'value' => 'required|integer|min:1',
                'payment_type' => 'required|in:all,cod,non_cod', 'min_type' => 'required|in:none,amount,qty', 'min_value' => 'nullable|integer|min:0',
                'exclusive' => 'boolean', 'max_uses' => 'nullable|integer|min:1', 'once_per_customer' => 'boolean',
                'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at', 'is_active' => 'boolean',
            ], 'code'],
            'ad-spends' => [AdSpend::class, [
                'platform' => 'required|in:meta,tiktok,google,shopee_ads,other', 'marketplace_shop_id' => ['nullable', $own('marketplace_shops')],
                'product_id' => ['nullable', $own('products')], 'spent_on' => 'required|date|before_or_equal:today',
                'amount' => 'required|integer|min:1|max:1000000000', 'campaign' => 'nullable|string|max:120', 'note' => 'nullable|string|max:200',
            ], 'campaign'],
            'payment-confirmations' => [PaymentConfirmation::class, ['status' => 'required|in:pending,approved,rejected'], 'account_name'],
            default => abort(404),
        };
    }

    public function index(Request $request, string $type)
    {
        [$model, , $searchCol] = $this->config($request, $type);
        $q = $model::where('user_id', $request->user()->id)
            ->when($request->query('search'), fn ($qq, $s) => $qq->where($searchCol, 'like', "%{$s}%"))
            ->latest();
        foreach (['type', 'platform', 'status', 'kind', 'is_active'] as $f) {
            $q->when($request->query($f) !== null && $request->query($f) !== '', fn ($qq) => $qq->where($f, $request->query($f)));
        }
        if ($type === 'coupons' && $request->query('state')) {
            $now = now();
            match ($request->query('state')) {
                'active' => $q->where('is_active', true)->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
                'scheduled' => $q->where('is_active', true)->where('starts_at', '>', $now),
                'expired' => $q->where('ends_at', '<=', $now),
                'disabled' => $q->where('is_active', false),
                default => null,
            };
        }
        if ($type === 'time-offs') {
            $q->with('staff:id,name');
        }

        return $q->paginate(min(1000, max(1, $request->integer('per_page', 10))));
    }

    public function store(Request $request, string $type)
    {
        [$model, $rules] = $this->config($request, $type);
        $data = $request->validate($rules);
        match ($type) {
            'domains' => Plans::ensureQuota($request->user(), 'domains'),
            'team' => Plans::ensureQuota($request->user(), 'team'),
            'pixels', 'rotators', 'coupons', 'affiliates' => Plans::ensureFeature($request->user(), $type),
            default => null,
        };
        if ($type === 'rotators') {
            $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(5));
        }
        if ($type === 'affiliates') {
            $data['code'] = $data['code'] ?? Str::upper(Str::random(6));
        }
        if ($type === 'domains' && ! CustomDomain::where('user_id', $request->user()->id)->exists()) {
            $data['is_default'] = false;
        }
        if ($type === 'team' && empty($data['password'])) {
            unset($data['password']);
        }
        $item = new $model($data);
        $item->user_id = $request->user()->id;
        $item->save();
        ActivityLog::record($request->user(), "{$type}.create", "Menambah {$type}");

        return response()->json($item, 201);
    }

    public function update(Request $request, string $type, int $id)
    {
        [$model, $rules] = $this->config($request, $type);
        $item = $model::where('user_id', $request->user()->id)->findOrFail($id);
        $partial = collect($rules)->map(fn ($r) => array_merge(['sometimes'], (array) (is_string($r) ? explode('|', $r) : $r)))->all();
        $data = $request->validate($partial);
        if ($type === 'team') {
            // Blank password keeps the current one; a new password, deactivation or a new email signs the member out.
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $signOut = isset($data['password']) || (($data['is_active'] ?? true) === false) || (array_key_exists('email', $data) && $data['email'] !== $item->email);
            if (array_key_exists('email', $data) && ! $data['email']) {
                $data['password'] = null;
            }
        }
        $item->update($data);
        if ($type === 'team' && ! empty($signOut)) {
            $item->revokeSessions();
        }

        if ($type === 'payment-confirmations' && ($data['status'] ?? null) === 'approved' && $item->order) {
            $item->order->update(['payment_status' => 'paid', 'paid_at' => now(), 'status' => $item->order->status === 'pending' ? 'processing' : $item->order->status]);
        }

        return $item->fresh();
    }

    public function destroy(Request $request, string $type, int $id)
    {
        [$model] = $this->config($request, $type);
        $item = $model::where('user_id', $request->user()->id)->findOrFail($id);
        if ($item instanceof TeamMember) {
            $item->revokeSessions();
        }
        $item->delete();

        return response()->noContent();
    }
}
