<?php

namespace App\Actions\StateMachines\M12Refund;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\RefundStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\Settlement\RefundPosting;
use App\Models\Finance\Refund;
use App\Support\LockingTransaction;

/**
 * Provider results of a refund (P8, the payment integration): M-12-T03 unknown result (the reservation stays, the same
 * reference is queried again — never a second refund), T05 trusted success after an unknown result (executed once),
 * and T04 trusted failure through T04RecordRefundFailure without an actor. No provider is bound in v1 (Q-42).
 */
final class RefundProviderResults
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly RefundPosting $posting,
    ) {}

    public function unresolved(Refund $refund, string $reference): Refund
    {
        LockingTransaction::run(function () use ($refund, $reference) {
            [$order, $locked, $head] = RefundStep::lock(null, $refund, null, null, 'T03');
            $from = RefundStep::move($locked, 'T03', ['reference' => $reference]);
            $this->transitions->record(StateMachine::M12, 'T03', $locked, $from, RefundStatus::Unresolved, null, TransitionRecorder::SOURCE_SYSTEM, null, ['reference' => $reference]);
            FinanceLock::touch($head);
        });

        return $refund->refresh();
    }

    public function succeeded(Refund $refund, string $reference): Refund
    {
        LockingTransaction::run(function () use ($refund, $reference) {
            [$order, $locked, $head] = RefundStep::lock(null, $refund, null, null, 'T05');
            if ($locked->reference !== null && $locked->reference !== $reference) {
                throw new InvalidTransition(StateMachine::M12, 'T05', 'reference_mismatch');
            }
            $number = $this->posting->execute($locked, $order, $head, 'T05', null, TransitionRecorder::SOURCE_SYSTEM);
            $from = RefundStep::move($locked, 'T05', ['reference' => $reference]);
            $this->transitions->record(StateMachine::M12, 'T05', $locked, $from, RefundStatus::Executed, null, TransitionRecorder::SOURCE_SYSTEM, null, ['number' => $number, 'reference' => $reference]);
            Audit::record(AuditAction::RefundExecuted, $order, null, after: [
                'refund' => $locked->id, 'number' => $number, 'amount_minor' => $locked->amount_minor, 'payment' => $locked->payment_id,
            ], source: TransitionRecorder::SOURCE_SYSTEM, label: $order->number);
        });

        return $refund->refresh();
    }
}
