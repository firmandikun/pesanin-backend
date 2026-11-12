<?php

namespace App\Support;

use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Subscriptions
{
    /** Issue an unpaid invoice, cancelling any earlier unpaid one. Renewing the active plan extends from its expiry. */
    public static function createInvoice(User $user, string $plan, string $billing, string $method): SubscriptionInvoice
    {
        $amount = Plans::price($plan, $billing);
        $start = Plans::current($user) === $plan && $user->plan_expires_at?->isFuture() ? $user->plan_expires_at->copy()->startOfDay() : now();
        $end = $billing === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth();
        $tax = (int) round($amount * Plans::TAX_PERCENT / 100);

        return DB::transaction(function () use ($user, $plan, $billing, $method, $amount, $tax, $start, $end) {
            $user->subscriptionInvoices()->where('status', 'unpaid')->update(['status' => 'cancelled']);

            return $user->subscriptionInvoices()->create([
                'number' => 'INV/PSN/'.now()->format('ymd').'/'.strtoupper(bin2hex(random_bytes(3))),
                'plan' => $plan, 'billing' => $billing,
                'amount' => $amount, 'tax' => $tax, 'total' => $amount + $tax,
                'method' => $method, 'pay_code' => self::payCode($method, $user->id),
                'status' => 'unpaid', 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'due_at' => now()->addDay(),
            ]);
        });
    }

    /** Payment received: activate the invoice's plan until the end of its period. */
    public static function markPaid(SubscriptionInvoice $invoice, string $via = ''): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            $invoice->user->forceFill([
                'plan' => $invoice->plan, 'plan_billing' => $invoice->billing,
                'plan_expires_at' => \Carbon\Carbon::parse($invoice->period_end)->endOfDay(), 'plan_auto_renew' => true,
            ])->save();
        });
        \App\Models\ActivityLog::record($invoice->user, 'billing.paid', "Pembayaran {$invoice->number} diterima".($via ? " ({$via})" : ''));
    }

    private static function payCode(string $method, int $userId): ?string
    {
        $prefix = ['va_bca' => '39358', 'va_bni' => '8808', 'va_bri' => '26215', 'va_mandiri' => '89608'][$method] ?? null;

        return $prefix ? $prefix.str_pad((string) $userId, 6, '0', STR_PAD_LEFT).random_int(1000, 9999) : null;
    }
}
