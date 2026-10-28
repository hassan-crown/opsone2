<?php

namespace App\Commissions;

use App\Agreements\AgreementApplied;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Support\LockingTransaction;

/**
 * After an agreement version becomes active: pin the sale on the first one and re-derive the accrual — a deposit
 * allocated by the new schedule earns its commission without a new payment (scenario 10), a lowered agreement lowers
 * it (scenario 11). Idempotent; the order of this and the schedule listener does not matter because the collection
 * listener re-derives too.
 */
final class PinSaleOnAgreement
{
    public function __construct(
        private readonly SaleOwnership $ownership,
        private readonly CommissionAccrual $accrual,
    ) {}

    public function handle(AgreementApplied $event): void
    {
        LockingTransaction::run(function () use ($event) {
            $order = FinanceLock::order($event->orderId, StateMachine::M33, 'T05');
            $this->ownership->pin($order);
            $this->accrual->recompute($order, 'agreement:'.$event->agreementId, null);
        });
    }
}
