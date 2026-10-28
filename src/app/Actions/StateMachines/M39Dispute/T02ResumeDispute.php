<?php

namespace App\Actions\StateMachines\M39Dispute;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Finance\FinanceWrite;
use App\Finance\Settlement\SettlementInput;
use App\Models\Finance\FinancialDispute;
use App\Models\User;

/**
 * M-39-T02 (P-85): the external party answered; the dispute is open again for its resolution.
 */
final class T02ResumeDispute
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, FinancialDispute $dispute, string $note, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): FinancialDispute
    {
        $note = SettlementInput::reason($note, 'note', 1000);
        $this->write->run('dispute.resume', $actor, $idempotencyKey, ['dispute' => $dispute->id, 'note' => $note, 'lock_version' => $lockVersion], function () use ($actor, $dispute, $note, $lockVersion, $source) {
            [$order, $locked] = DisputeStep::lock($actor, $dispute, $lockVersion, 'T02');
            DisputeStep::move($this->transitions, $locked, 'T02', [], $actor, $source, $note);
            Audit::record(AuditAction::DisputeResumed, $order, $actor, after: ['dispute' => $locked->id], reason: $note, source: $source, label: $order->number);

            return ['dispute_id' => $locked->id];
        });

        return $dispute->refresh();
    }
}
