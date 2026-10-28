<?php

namespace App\Settlement;

use App\Finance\Settlement\RefundExecuted;
use App\Models\Order;
use App\Support\LockingTransaction;

/**
 * An executed refund (P6 M-12, after commit) may finish a refund item of the order's settlement follow-up. Idempotent:
 * the follow-up re-reads the executed refunds, so a replayed event changes nothing.
 */
final class SyncSettlementOnRefund
{
    public function __construct(private readonly SettlementFollowUps $settlement) {}

    public function handle(RefundExecuted $event): void
    {
        if (SettlementFollowUps::forOrder(Order::query()->findOrFail($event->orderId)) === null) {
            return;
        }
        LockingTransaction::run(fn () => $this->settlement->sync(Order::query()->lockForUpdate()->findOrFail($event->orderId)));
    }
}
