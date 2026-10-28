<?php

namespace App\Finance\Settlement;

/**
 * Dispatched after commit when a refund's money really left (M-12-T02/T05): the net collected of the order dropped
 * by `amountMinor` once. 6d recomputes commission from the ledger; listeners are idempotent on `refundId`.
 */
final readonly class RefundExecuted
{
    public function __construct(
        public int $refundId,
        public int $orderId,
        public int $paymentId,
        public int $amountMinor,
        public string $number,
    ) {}
}
