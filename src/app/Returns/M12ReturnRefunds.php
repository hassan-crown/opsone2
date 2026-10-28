<?php

namespace App\Returns;

use App\Actions\StateMachines\M12Refund\T00RequestRefund;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Returns\OrderReturn;
use App\Models\User;
use App\Money\Money;
use App\Returns\Contracts\ReturnRefunds;

/**
 * ReturnRefunds on P6c's M-12-T00: the refund request authorizes P-42 itself, reserves the amount from the payment's
 * refundable limit (409 `not_refundable` above it) and is replayed by its own idempotency key
 * (`return-refund:{return}:{key}`), so a retried return action never asks twice.
 */
final class M12ReturnRefunds implements ReturnRefunds
{
    public function __construct(private readonly T00RequestRefund $refunds) {}

    public function request(User $actor, OrderReturn $return, Payment $payment, Money $amount, string $method, string $reason, string $idempotencyKey): Refund
    {
        return $this->refunds->handle($actor, $return->order, [
            'payment_id' => $payment->id, 'amount' => $amount->toInput(), 'method' => $method, 'reason' => $reason,
        ], null, mb_substr('return-refund:'.$return->id.':'.$idempotencyKey, 0, 100));
    }
}
