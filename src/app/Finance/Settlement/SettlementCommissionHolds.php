<?php

namespace App\Finance\Settlement;

use App\Commissions\LedgerSettlementHolds;
use App\Commissions\SettlementHolds;
use App\Enums\DisputeStatus;
use App\Enums\RefundStatus;
use App\Models\Finance\FinancialDispute;
use App\Models\Finance\Refund;
use App\Models\Order;

/**
 * SettlementHolds decorated by 6c (SRS-INT-06, ADR-0016): an order with a refund still reserving its amount or an
 * unresolved financial dispute is held from commission payouts until it settles; otherwise the ledger rule applies.
 */
final class SettlementCommissionHolds implements SettlementHolds
{
    public function __construct(private readonly LedgerSettlementHolds $ledger) {}

    public function holdOf(Order $order): ?string
    {
        $pending = array_map(fn (RefundStatus $s) => $s->value, array_values(array_filter(RefundStatus::cases(), fn (RefundStatus $s) => $s->reserves())));
        if (Refund::query()->where('order_id', $order->id)->whereIn('status', $pending)->exists()) {
            return 'refund';
        }
        if (FinancialDispute::query()->where('order_id', $order->id)->where('status', '!=', DisputeStatus::Resolved->value)->exists()) {
            return 'dispute';
        }

        return $this->ledger->holdOf($order);
    }
}
