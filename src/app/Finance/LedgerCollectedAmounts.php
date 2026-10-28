<?php

namespace App\Finance;

use App\Agreements\CollectedAmounts;
use App\Models\Order;
use App\Money\Money;

/**
 * 6a's «what finance collected» bound to the ledger: the order's net collected after transfers and reversals (and,
 * from 6c, executed refunds). Read inside the agreement's transaction for «settlement needed» (AGR-06).
 */
final class LedgerCollectedAmounts implements CollectedAmounts
{
    public function collectedFor(Order $order): Money
    {
        return MoneyLedger::collected($order->id);
    }
}
