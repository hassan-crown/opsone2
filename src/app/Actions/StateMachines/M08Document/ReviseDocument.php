<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\Access;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\Numbers;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementDocument;
use App\Models\User;
use App\Workflow\Transition;

/**
 * Starts a replacement of a sent quote or contract: a new draft copying its content, linked through `replaces_id`.
 * The sent one stays as it is until the replacement goes out (M-08-T04) or, if approved, until the replacement is
 * approved (T07). An approved quote is changed only through an agreement change (M-09). A design is replaced by
 * uploading the new file (T00UploadDesign).
 */
final class ReviseDocument
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly Numbers $numbers,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, AgreementDocument $previous, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        $result = $this->write->run('document.revise', $actor, $idempotencyKey, ['document_id' => $previous->id], function () use ($actor, $previous, $source) {
            [$order, $previous] = AgreementLock::document($previous, null, 'T04');
            Access::authorize($actor, $previous->kind->preparePermission(), $previous);
            $replaceable = [DocumentStatus::AwaitingCustomer, DocumentStatus::ChangesRequested, DocumentStatus::Expired];
            if ($previous->kind === DocumentKind::Contract) {
                $replaceable[] = DocumentStatus::Approved;
            }
            if ($previous->kind === DocumentKind::Design || ! in_array($previous->status, $replaceable, true)) {
                Transition::refuse(StateMachine::M08, 'T04', $previous->status === DocumentStatus::Approved ? 'use_agreement_change' : 'not_replaceable');
            }
            if (AgreementDocument::query()->where('replaces_id', $previous->id)->where('status', DocumentStatus::Draft->value)->exists()) {
                Transition::refuse(StateMachine::M08, 'T04', 'revision_open');
            }

            $document = AgreementDocument::query()->create([
                'order_id' => $order->id, 'kind' => $previous->kind, 'status' => DocumentStatus::Draft,
                'number' => $this->numbers->document($previous->kind), 'replaces_id' => $previous->id,
                'content' => $previous->content, 'created_by' => $actor->id,
            ]);
            $this->transitions->record(StateMachine::M08, 'T00', $document, null, DocumentStatus::Draft, $actor, $source, context: [
                'order_id' => $order->id, 'replaces' => $previous->id,
            ]);
            Audit::record(AuditAction::DocumentCreated, $order, $actor, after: ['document' => $document->number, 'kind' => $document->kind->value, 'replaces' => $previous->number],
                source: $source, label: $order->number);

            return ['document_id' => $document->id];
        });

        return AgreementDocument::query()->findOrFail((int) $result['result']['document_id']);
    }
}
