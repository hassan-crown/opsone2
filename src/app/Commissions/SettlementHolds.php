<?php

namespace App\Commissions;

use App\Models\Order;

/**
 * Whether an order's commission is held from payout (SRS-INT-06, P06): while a settlement, refund, cancellation or
 * dispute of the order is open, its unpaid balance cannot be paid — the accrual itself is still recorded and the
 * member's other orders stay payable. 6c decorates the default with its pending refunds and open disputes.
 */
interface SettlementHolds
{
    /**
     * The reason code when held (`settlement`, `refund`, `dispute`, …), null when payable.
     */
    public function holdOf(Order $order): ?string;
}
