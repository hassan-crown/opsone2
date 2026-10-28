<?php

namespace App\Finance;

use App\Models\Finance\OrderDue;
use App\Money\Money;

/**
 * Default DisputedAmounts until 6c binds disputes.
 */
final class NoDisputes implements DisputedAmounts
{
    public function disputedFor(OrderDue $due): Money
    {
        return Money::zero();
    }
}
