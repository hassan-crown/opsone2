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
use Illuminate\Validation\ValidationException;

/**
 * M-08-T06 (P-30): extend an expired quote with the same content and a new period; a different content is sent as a
 * replacement instead (ReviseDocument → T01/T04). The decision is recorded.
 */
final class T06ExtendDocument
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, AgreementDocument $document, int $days, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        if ($days < 1 || $days > T01SendDocument::MAX_VALIDITY_DAYS) {
            throw ValidationException::withMessages(['validity_days' => __('agreements.validation.validity')]);
        }

        return LockingTransaction::run(function () use ($actor, $document, $days, $lockVersion, $source) {
            [$order, $document] = AgreementLock::document($document, $lockVersion, 'T06');
            Access::authorize($actor, Permission::P30, $order);
            Transition::check(StateMachine::M08, DocumentStatus::transitions(), $document->status, 'T06');

            $before = $document->valid_until?->toDateString();
            $document->forceFill(['status' => DocumentStatus::AwaitingCustomer, 'valid_until' => now()->addDays($days)->toDateString(), 'lock_version' => $document->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M08, 'T06', $document, DocumentStatus::Expired, DocumentStatus::AwaitingCustomer, $actor, $source, context: [
                'order_id' => $order->id, 'valid_until' => $document->valid_until?->toDateString(),
            ]);
            Audit::record(AuditAction::DocumentExtended, $order, $actor, before: ['document' => $document->number, 'valid_until' => $before],
                after: ['valid_until' => $document->valid_until?->toDateString()], source: $source, label: $order->number);

            return $document;
        });
    }
}
