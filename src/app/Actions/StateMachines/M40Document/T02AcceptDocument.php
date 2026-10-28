<?php

namespace App\Actions\StateMachines\M40Document;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Finance\FinanceWrite;
use App\Models\Finance\ExternalDocument;
use App\Models\User;

/**
 * M-40-T02 (P-44): the internal review accepts the linked document. Accepted means linked and reviewed, not proven
 * compliant.
 */
final class T02AcceptDocument
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, ExternalDocument $document, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): ExternalDocument
    {
        $this->write->run('document.accept', $actor, $idempotencyKey, ['document' => $document->id, 'lock_version' => $lockVersion], function () use ($actor, $document, $lockVersion, $source) {
            [$order, $locked] = DocumentStep::lock($actor, $document, $lockVersion, 'T02');
            DocumentStep::move($this->transitions, $locked, 'T02', ['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'correction_reason' => null], $actor, $source);
            Audit::record(AuditAction::ExternalDocumentAccepted, $order, $actor, after: ['document' => $locked->id, 'number' => $locked->number], source: $source, label: $order->number);

            return ['document_id' => $locked->id];
        });

        return $document->refresh();
    }
}
