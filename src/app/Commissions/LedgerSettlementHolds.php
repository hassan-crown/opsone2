<?php

namespace App\Commissions;

use App\Agreements\OrderAgreement;
use App\Finance\MoneyLedger;
use App\Models\Order;

/**
 * Default hold from the ledger alone: more money on the order than its active agreement (a lowered agreement or an
 * overpayment waiting for a refund or transfer, AGR-06) — the settlement is open until the excess leaves.
 */
final class LedgerSettlementHolds implements SettlementHolds
{
    public function holdOf(Order $order): ?string
    {
        $agreed = OrderAgreement::value($order);
        if ($agreed === null) {
            return null;
        }

        return MoneyLedger::collected($order->id)->compare($agreed) > 0 ? 'settlement' : null;
    }
}
