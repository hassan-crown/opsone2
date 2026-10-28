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

/**
 * M-12-T06 (P-42): withdraw a request that was never sent; its reservation is released. A refund already sent cannot
 * be cancelled on paper — its result (T02/T04) is recorded instead.
 */
final class T06CancelRefund
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, Refund $refund, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): Refund
    {
        $reason = SettlementInput::reason($reason);
        $this->write->run('refund.cancel', $actor, $idempotencyKey, ['refund' => $refund->id, 'reason' => $reason, 'lock_version' => $lockVersion], function () use ($actor, $refund, $reason, $lockVersion, $source) {
            [$order, $locked, $head] = RefundStep::lock($actor, $refund, Permission::P42, $lockVersion, 'T06');
            $from = RefundStep::move($locked, 'T06', ['cancel_reason' => $reason]);
            $this->transitions->record(StateMachine::M12, 'T06', $locked, $from, RefundStatus::Cancelled, $actor, $source, $reason);
            FinanceLock::touch($head);
            Audit::record(AuditAction::RefundCancelled, $order, $actor, after: ['refund' => $locked->id], reason: $reason, source: $source, label: $order->number);

            return ['refund_id' => $locked->id];
        });

        return $refund->refresh();
    }
}
