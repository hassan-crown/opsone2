<?php

namespace App\Returns\Contracts;

use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Returns\OrderReturn;
use App\Models\User;
use App\Money\Money;

/**
 * The money side of a return goes through the P6 refund flow (M-12): the request reserves its amount from the
 * payment's refundable limit under the order lock and executes only by P-43 with proof. Called inside the return's
 * transaction, with the order row already locked.
 */
interface ReturnRefunds
{
    public function request(User $actor, OrderReturn $return, Payment $payment, Money $amount, string $method, string $reason, string $idempotencyKey): Refund;
}
