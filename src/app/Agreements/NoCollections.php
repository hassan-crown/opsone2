<?php

namespace App\Agreements;

use App\Models\Order;
use App\Money\Money;

/**
 * Default CollectedAmounts until P6b binds the real ledger.
 */
final class NoCollections implements CollectedAmounts
{
    public function collectedFor(Order $order): Money
    {
        return Money::zero();
    }
}
