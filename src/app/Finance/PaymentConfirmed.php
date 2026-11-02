<?php

namespace App\Finance;

/**
 * Dispatched after commit when a payment became real money (M-11 → confirmed, `PaymentPosting`). `orderIds` are the
 * orders its shares went to. Listeners are idempotent on the payment (ADR-0033 issues the order's tax invoice).
 */
final readonly class PaymentConfirmed
{
    /**
     * @param  list<int>  $orderIds
     */
    public function __construct(public int $paymentId, public array $orderIds) {}
}
