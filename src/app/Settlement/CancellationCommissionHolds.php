<?php

namespace App\Settlement;

use App\Commissions\SettlementHolds;
use App\Models\Order;
use App\Models\Settlement\SettlementItem;

/**
 * Decorates the 6c holds (ADR-0016/0020): an order whose settlement follow-up still owes the customer a refund is held
 * from commission payouts as «cancellation» until that item is done; a pending refund or a dispute keeps its own code.
 */
final class CancellationCommissionHolds implements SettlementHolds
{
    public function __construct(private readonly SettlementHolds $inner) {}

    public function holdOf(Order $order): ?string
    {
        $code = $this->inner->holdOf($order);
        if ($code === 'refund' || $code === 'dispute') {
            return $code;
        }
        if (SettlementItem::query()->where('order_id', $order->id)->where('kind', SettlementItem::REFUND)->where('status', SettlementItem::OPEN)->exists()) {
            return 'cancellation';
        }

        return $code;
    }
}
