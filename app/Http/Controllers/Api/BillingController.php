<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SubscriptionInvoice;
use App\Support\Plans;
use App\Support\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Pesanin subscription: plan status, usage, and invoices. No payment gateway yet — invoices carry static pay codes. */
class BillingController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $this->expireOverdue($user->id);

        if ($mt = \App\Support\Midtrans\Midtrans::platform()) {
            foreach ($user->subscriptionInvoices()->where('status', 'unpaid')->get() as $inv) {
                try {
                    $st = $mt->status(\App\Support\Midtrans\Midtrans::invoiceOrderId($inv));
                    // No transaction yet (payment page not opened, Midtrans answers 404): still waiting, not failed.
                    if (empty($st['transaction_status'])) {
                        continue;
                    }
                    $outcome = \App\Support\Midtrans\Midtrans::outcome($st);
                    if ($outcome === 'paid') {
                        Subscriptions::markPaid($inv, 'Midtrans');
                    } elseif ($outcome === 'failed') {
                        $inv->update(['status' => 'cancelled']);
                    }
                } catch (\Throwable) {
                }
            }
        }

        $plan = Plans::current($user);

        return [
            'plan' => $plan,
            'billing' => $plan === 'gratis' ? null : $user->plan_billing,
            'expires_at' => $plan === 'gratis' ? null : $user->plan_expires_at,
            'auto_renew' => (bool) $user->plan_auto_renew,
            'lapsed' => $user->plan !== 'gratis' && $plan === 'gratis',
            'subscription' => Plans::status($user),
            'trial_days' => Plans::TRIAL_DAYS,
            'grace_days' => Plans::GRACE_DAYS,
            'usage' => Plans::usage($user),
            'plans' => collect(Plans::all())->map(fn ($p, $id) => [
                'id' => $id, 'name' => $p['name'], 'rank' => $p['rank'], 'monthly' => $p['monthly'],
                'yearly' => Plans::price($id, 'yearly'), 'limits' => $p['limits'],
                'features' => collect(Plans::features())->filter(fn ($need) => $p['rank'] >= Plans::all()[$need]['rank'])->keys()->values(),
            ])->values(),
            'tax_percent' => Plans::TAX_PERCENT,
            'methods' => Plans::METHODS,
            'invoices' => $user->subscriptionInvoices()->latest('id')->limit(30)->get(),
            'can_simulate' => app()->isLocal(),
        ];
    }

    public function checkout(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(array_filter(Plans::all(), fn ($p) => $p['monthly'] > 0)))],
            'billing' => 'required|in:monthly,yearly',
            'method' => ['required', Rule::in(Plans::METHODS)],
        ]);

        $invoice = Subscriptions::createInvoice($user, $data['plan'], $data['billing'], $data['method']);
        ActivityLog::record($user, 'billing.checkout', "Membuat tagihan {$invoice->number} paket ".Plans::all()[$data['plan']]['name']);

        // Pay through Pesanin's own Midtrans account when configured (.env MIDTRANS_SERVER_KEY).
        if ($mt = \App\Support\Midtrans\Midtrans::platform()) {
            try {
                $snap = $mt->forInvoice($invoice->load('user'), rtrim(config('app.frontend_url'), '/').'/settings/billing');
                $invoice->update(['payment_url' => $snap['redirect_url']]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Midtrans invoice {$invoice->number}: {$e->getMessage()}");
            }
        }

        return response()->json($invoice, 201);
    }

    public function cancel(Request $request, SubscriptionInvoice $invoice)
    {
        abort_unless($invoice->user_id === $request->user()->id, 404);
        abort_unless($invoice->status === 'unpaid', 422, 'Tagihan ini sudah tidak bisa dibatalkan.');
        $invoice->update(['status' => 'cancelled']);

        return $invoice;
    }

    /** Local-only stand-in for a payment gateway callback. */
    public function simulatePay(Request $request, SubscriptionInvoice $invoice)
    {
        abort_unless(app()->isLocal(), 404);
        abort_unless($invoice->user_id === $request->user()->id, 404);
        abort_unless($invoice->status === 'unpaid', 422, 'Tagihan ini tidak menunggu pembayaran.');

        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            $invoice->user->forceFill([
                'plan' => $invoice->plan, 'plan_billing' => $invoice->billing,
                'plan_expires_at' => Carbon::parse($invoice->period_end)->endOfDay(), 'plan_auto_renew' => true,
            ])->save();
        });
        ActivityLog::record($request->user(), 'billing.paid', "Pembayaran {$invoice->number} diterima");

        return $invoice->fresh();
    }

    /** Stay on the free plan once the trial or a paid plan is over (unlocks the app with Gratis limits). */
    public function chooseFree(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array(Plans::status($user)['status'], ['expired', 'free'], true), 422, 'Paket kamu masih aktif.');
        $user->forceFill(['plan' => 'gratis', 'plan_billing' => null, 'plan_expires_at' => null, 'plan_auto_renew' => false])->save();
        ActivityLog::record($user, 'billing.free', 'Memilih paket Gratis');

        return $user->fresh();
    }

    public function autoRenew(Request $request)
    {
        $data = $request->validate(['auto_renew' => 'required|boolean']);
        $request->user()->forceFill(['plan_auto_renew' => $data['auto_renew']])->save();

        return ['auto_renew' => $data['auto_renew']];
    }

    private function expireOverdue(int $userId): void
    {
        SubscriptionInvoice::where('user_id', $userId)->where('status', 'unpaid')->where('due_at', '<', now())->update(['status' => 'expired']);
    }
}
