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
 * M-39-T01 (P-85): the dispute waits for an external party (the bank, the provider, the customer's lawyer…).
 */
final class T01AwaitExternal
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, FinancialDispute $dispute, string $party, ?string $note, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): FinancialDispute
    {
        $party = SettlementInput::reason($party, 'external_party', 120);
        $note = trim((string) $note) ?: null;
        $this->write->run('dispute.await', $actor, $idempotencyKey, ['dispute' => $dispute->id, 'party' => $party, 'note' => $note, 'lock_version' => $lockVersion], function () use ($actor, $dispute, $party, $note, $lockVersion, $source) {
            [$order, $locked] = DisputeStep::lock($actor, $dispute, $lockVersion, 'T01');
            DisputeStep::move($this->transitions, $locked, 'T01', ['external_party' => $party], $actor, $source, $note);
            Audit::record(AuditAction::DisputeAwaiting, $order, $actor, after: ['dispute' => $locked->id, 'party' => $party], reason: $note, source: $source, label: $order->number);

            return ['dispute_id' => $locked->id];
        });

        return $dispute->refresh();
    }
}
