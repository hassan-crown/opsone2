<?php

namespace App\Finance;

use App\Access\TransitionRecorder;
use App\Agreements\AgreementApplied;
use App\Enums\StateMachine;
use App\Models\Finance\MoneyEntry;
use App\Support\LockingTransaction;

/**
 * After an agreement version becomes active (first approval or an applied change): build or follow the schedule
 * from `OrderAgreement`, allocate the credit — a deposit received before the agreement is allocated now without a
 * new receipt (FIN-03, scenario 10) — and re-check the stage collection conditions. Idempotent on the agreement id
 * (OrderSchedule::sync), so a replayed or late event changes nothing.
 */
final class SyncScheduleOnAgreement
{
    public function __construct(
        private readonly OrderSchedule $schedule,
        private readonly CollectionConditions $conditions,
    ) {}

    public function handle(AgreementApplied $event): void
    {
        LockingTransaction::run(function () use ($event) {
            $order = FinanceLock::order($event->orderId, StateMachine::M11, 'sync');
            $head = FinanceLock::head($order);
            if (! $this->schedule->sync($order, $head, null, TransitionRecorder::SOURCE_SYSTEM)) {
                return;
            }
            $latest = MoneyEntry::query()->where('order_id', $order->id)->max('payment_id');
            if ($latest !== null) {
                $this->conditions->evaluate($order, (int) $latest, null);
            }
        });
    }
}
