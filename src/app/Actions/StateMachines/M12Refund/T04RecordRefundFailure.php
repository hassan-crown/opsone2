<?php

namespace App\Actions\StateMachines\M12Refund;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\RefundStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\Settlement\SettlementInput;
use App\Models\Finance\Refund;
use App\Models\User;
use App\Support\LockingTransaction;

/**
 * M-12-T04: a proven failure (the bank returned the transfer). Nothing leaves the ledger and the reservation is
 * released; a retry (T07) re-checks the limit. In v1 the person with P-43 records it with the reason (Q-42); a provider
 * reports it through RefundProviderResults in P8.
 */
final class T04RecordRefundFailure
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(?User $actor, Refund $refund, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): Refund
    {
        $reason = SettlementInput::reason($reason);
        $work = function () use ($actor, $refund, $reason, $lockVersion, $source) {
            [$order, $locked, $head] = RefundStep::lock($actor, $refund, $actor === null ? null : Permission::P43, $lockVersion, 'T04');
            $from = RefundStep::move($locked, 'T04', ['failure_reason' => $reason]);
            $this->transitions->record(StateMachine::M12, 'T04', $locked, $from, RefundStatus::Failed, $actor, $source, $reason, ['attempt' => $locked->attempt]);
            FinanceLock::touch($head);
            Audit::record(AuditAction::RefundFailed, $order, $actor, after: ['refund' => $locked->id, 'attempt' => $locked->attempt], reason: $reason, source: $source, label: $order->number);

            return ['refund_id' => $locked->id];
        };

        if ($actor === null) {
            LockingTransaction::run($work);
        } else {
            $this->write->run('refund.fail', $actor, $idempotencyKey, ['refund' => $refund->id, 'reason' => $reason, 'lock_version' => $lockVersion], $work);
        }

        return $refund->refresh();
    }
}
