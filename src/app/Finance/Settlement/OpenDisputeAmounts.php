<?php

namespace App\Finance\Settlement;

use App\Enums\DisputeStatus;
use App\Finance\DisputedAmounts;
use App\Models\Finance\FinancialDispute;
use App\Models\Finance\OrderDue;
use App\Money\Money;

/**
 * DisputedAmounts bound to open disputes (FIN-11/13): the part of a due named by an open or awaiting dispute is never
 * reminded about. Re-read at send time; a resolution never sends at once and never drops the debt.
 */
final class OpenDisputeAmounts implements DisputedAmounts
{
    public function disputedFor(OrderDue $due): Money
    {
        return Money::of(min($due->amount_minor, (int) FinancialDispute::query()->where('order_due_id', $due->id)
            ->whereIn('status', [DisputeStatus::Open->value, DisputeStatus::AwaitingExternal->value])->sum('amount_minor')));
    }
}
