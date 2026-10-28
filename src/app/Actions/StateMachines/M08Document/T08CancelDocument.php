<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementDocument;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;

/**
 * M-08-T08 (P-30): withdraw an approval request with a reason. An approved document is never cancelled — the agreement
 * changes through M-09, not by deleting what was approved.
 */
final class T08CancelDocument
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, AgreementDocument $document, string $reason, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $document, $reason, $lockVersion, $source) {
            [$order, $document] = AgreementLock::document($document, $lockVersion, 'T08');
            Access::authorize($actor, Permission::P30, $order);
            $from = $document->status;
            Transition::check(StateMachine::M08, DocumentStatus::transitions(), $from, 'T08');

            $document->forceFill(['status' => DocumentStatus::Cancelled, 'cancelled_reason' => $reason, 'lock_version' => $document->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M08, 'T08', $document, $from, DocumentStatus::Cancelled, $actor, $source, $reason, ['order_id' => $order->id]);
            Audit::record(AuditAction::DocumentCancelled, $order, $actor, before: ['document' => $document->number, 'status' => $from->value],
                after: ['status' => DocumentStatus::Cancelled->value], reason: $reason, source: $source, label: $order->number);

            return $document;
        });
    }
}
