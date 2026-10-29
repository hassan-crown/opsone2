<?php

namespace App\Commissions;

use App\Enums\PayoutStatus;
use App\Models\Commissions\CommissionEntry;
use App\Models\Commissions\CommissionPayout;
use App\Models\Commissions\CommissionPayoutLine;
use App\Models\Commissions\OrderSale;
use Carbon\CarbonInterface;

/**
 * A member's commission balance (SAL-14, SAL-15), derived on read:
 *
 * - per sale: accrued (entries up to the period end) − taken (lines of reserved, paid or corrected payouts);
 * - a held sale (open settlement/refund/dispute, `SettlementHolds`) is left out of a payout — only that order, the
 *   rest of the statement stays payable;
 * - adjustments: what a corrected payout (M-33-T04) did not take or took beyond its lines;
 * - available = payable sales + adjustments. Negative is carried to the next statement, never deducted from a salary.
 */
final class CommissionBalances
{
    public function __construct(private readonly SettlementHolds $holds) {}

    /**
     * @param  list<int>|null  $saleIds  only these sales (the viewer's scope); null = all of the member's
     * @return list<array{sale: OrderSale, accrued: int, taken: int, available: int, hold: string|null}>
     */
    public function sales(int $userId, ?CarbonInterface $until = null, ?array $saleIds = null): array
    {
        $sales = OrderSale::query()->with('order.customer')->where('user_id', $userId)
            ->when($saleIds !== null, fn ($q) => $q->whereIn('id', $saleIds))
            ->orderBy('id')->get();
        if ($sales->isEmpty()) {
            return [];
        }
        $ids = $sales->pluck('id')->all();
        $accrued = CommissionEntry::query()->whereIn('order_sale_id', $ids)
            ->when($until !== null, fn ($q) => $q->where('created_at', '<=', $until->copy()->endOfDay()))
            ->groupBy('order_sale_id')->selectRaw('order_sale_id, SUM(amount_minor) as total')->pluck('total', 'order_sale_id');
        $taken = CommissionPayoutLine::query()->whereIn('order_sale_id', $ids)
            ->whereIn('payout_id', CommissionPayout::query()->whereIn('status', PayoutStatus::holding())->select('id'))
            ->groupBy('order_sale_id')->selectRaw('order_sale_id, SUM(amount_minor) as total')->pluck('total', 'order_sale_id');

        $holds = [];

        return array_values($sales->map(function (OrderSale $sale) use ($accrued, $taken, &$holds) {
            $a = (int) ($accrued[$sale->id] ?? 0);
            $t = (int) ($taken[$sale->id] ?? 0);
            $holds[$sale->order_id] ??= ['hold' => $this->holds->holdOf($sale->order)];

            return ['sale' => $sale, 'accrued' => $a, 'taken' => $t, 'available' => $a - $t, 'hold' => $holds[$sale->order_id]['hold']];
        })->all());
    }

    /**
     * Member-level difference left by corrected payouts and not yet taken by another payout.
     */
    public function adjustments(int $userId): int
    {
        $corrected = CommissionPayout::query()->where('user_id', $userId)->where('status', PayoutStatus::Corrected->value)->get()
            ->sum(fn (CommissionPayout $p) => (int) $p->amount_minor - $p->effectiveMinor());
        $taken = (int) CommissionPayoutLine::query()->whereNull('order_sale_id')
            ->whereIn('payout_id', CommissionPayout::query()->where('user_id', $userId)->whereIn('status', PayoutStatus::holding())->select('id'))
            ->sum('amount_minor');

        return (int) $corrected - $taken;
    }

    /**
     * @param  list<int>|null  $saleIds
     * @return array{accrued_minor: int, paid_minor: int, reserved_minor: int, held_minor: int, available_minor: int, sales: int}
     */
    public function summary(int $userId, ?array $saleIds = null): array
    {
        $rows = $this->sales($userId, null, $saleIds);
        $held = $available = 0;
        foreach ($rows as $row) {
            if ($row['hold'] !== null) {
                $held += max(0, $row['available']);
            } else {
                $available += $row['available'];
            }
        }
        $payouts = CommissionPayout::query()->where('user_id', $userId)->whereIn('status', PayoutStatus::holding())->get();

        return [
            'accrued_minor' => array_sum(array_column($rows, 'accrued')),
            'paid_minor' => (int) $payouts->filter(fn (CommissionPayout $p) => $p->status !== PayoutStatus::Reserved)->sum(fn (CommissionPayout $p) => $p->effectiveMinor()),
            'reserved_minor' => (int) $payouts->filter(fn (CommissionPayout $p) => $p->status === PayoutStatus::Reserved)->sum('amount_minor'),
            'held_minor' => $held,
            'available_minor' => $available + ($saleIds === null ? $this->adjustments($userId) : 0),
            'sales' => count($rows),
        ];
    }

    /**
     * What a payout of the member up to `$until` would take now: every payable sale with a balance, and the
     * adjustments.
     *
     * @return array{lines: list<array{order_sale_id: int|null, order_id: int|null, amount_minor: int}>, amount_minor: int, held: list<array{order_id: int, hold: string, amount_minor: int}>}
     */
    public function payable(int $userId, CarbonInterface $until): array
    {
        $lines = $held = [];
        foreach ($this->sales($userId, $until) as $row) {
            if ($row['hold'] !== null) {
                if ($row['available'] !== 0) {
                    $held[] = ['order_id' => $row['sale']->order_id, 'hold' => $row['hold'], 'amount_minor' => $row['available']];
                }

                continue;
            }
            if ($row['available'] !== 0) {
                $lines[] = ['order_sale_id' => $row['sale']->id, 'order_id' => $row['sale']->order_id, 'amount_minor' => $row['available']];
            }
        }
        $adjustment = $this->adjustments($userId);
        if ($adjustment !== 0) {
            $lines[] = ['order_sale_id' => null, 'order_id' => null, 'amount_minor' => $adjustment];
        }

        return ['lines' => $lines, 'amount_minor' => array_sum(array_column($lines, 'amount_minor')), 'held' => $held];
    }
}
