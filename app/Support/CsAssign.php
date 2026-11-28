<?php

namespace App\Support;

use App\Models\CsStaff;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * CS penanggung jawab pesanan: every web checkout order gets one CS who confirms it with the buyer.
 * Candidates = the CS chosen on the product (Setelah Checkout → CS), else every active CS of the seller.
 * CS on shift now (same shifts / time off as WA Rotator) come first; with nobody on shift the order still gets
 * an owner from the whole list so it is never left unhandled. Among them: round-robin (whoever waited longest).
 */
class CsAssign
{
    public static function pick(Product $product): ?CsStaff
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($product->success_settings['cs_staff_ids'] ?? []))));
        $staff = CsStaff::where('user_id', $product->user_id)->where('is_active', true)
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))->lockForUpdate()->get();
        if ($staff->isEmpty()) {
            return null;
        }
        $onDuty = CsStaff::onDuty($staff);
        $cs = self::nextInTurn($onDuty->isNotEmpty() ? $onDuty : $staff);
        $cs->forceFill(['last_assigned_at' => now()])->save();

        return $cs;
    }

    /** Whoever got an order the longest ago (never = first), ties by id. */
    public static function nextInTurn(Collection $staff): CsStaff
    {
        return $staff->sortBy(fn (CsStaff $s) => [$s->last_assigned_at?->getTimestamp() ?? 0, $s->id])->first();
    }

    /**
     * Kinerja CS: orders assigned per CS in a period, how many were paid and the paid revenue.
     * @return array<int, array{id: int|null, name: string, orders: int, paid: int, contacted: int, revenue: int, closing_rate: float}>
     */
    public static function summary(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = Order::where('user_id', $user->id)->where('channel', '!=', 'shopee')->where('is_abandoned', false)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("cs_staff_id, COUNT(*) as orders, SUM(payment_status = 'paid') as paid, SUM(cs_contacted_at IS NOT NULL) as contacted,
                SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END) as revenue")
            ->groupBy('cs_staff_id')->get();
        $names = CsStaff::where('user_id', $user->id)->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'id' => $r->cs_staff_id ? (int) $r->cs_staff_id : null,
            'name' => $r->cs_staff_id ? ($names[$r->cs_staff_id] ?? 'CS dihapus') : 'Tanpa CS',
            'orders' => (int) $r->orders, 'paid' => (int) $r->paid, 'contacted' => (int) $r->contacted, 'revenue' => (int) $r->revenue,
            'closing_rate' => $r->orders ? round($r->paid / $r->orders * 100, 1) : 0.0,
        ])->sortByDesc('orders')->values()->all();
    }
}
