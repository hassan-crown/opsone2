<?php

namespace App\Delivery;

/**
 * Dispatched after commit when a confirmed delivery is corrected (M-14-T03, ADR-0035). Nothing listens yet: the due
 * rule keeps the first confirmed note's date. It is the hook a future configurable payment-schedule engine reads
 * (with `delivery_corrections.first_delivery_on`). Listeners must be idempotent on `correctionId`.
 */
final readonly class DeliveryCorrected
{
    public function __construct(
        public int $orderId,
        public int $correctionId,
    ) {}
}
