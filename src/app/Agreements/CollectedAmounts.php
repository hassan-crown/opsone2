<?php

namespace App\Agreements;

use App\Models\Order;
use App\Money\Money;

/**
 * What finance has collected for an order (net of refunds). Owned by P6b (collections); until it is bound, nothing
 * is collected. Read inside the agreement transaction to compute «settlement needed» when a change lowers the value
 * below what was collected (AGR-06) — never a refund.
 */
interface CollectedAmounts
{
    public function collectedFor(Order $order): Money;
}
