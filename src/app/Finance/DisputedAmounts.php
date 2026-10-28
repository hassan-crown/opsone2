<?php

namespace App\Finance;

use App\Models\Finance\OrderDue;
use App\Money\Money;

/**
 * The part of a due held by an open financial dispute (FIN-11/13): never reminded about. Owned by 6c (disputes);
 * until it is bound nothing is disputed.
 */
interface DisputedAmounts
{
    public function disputedFor(OrderDue $due): Money;
}
