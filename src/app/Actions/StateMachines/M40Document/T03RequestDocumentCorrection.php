<?php

namespace App\Actions\StateMachines\M40Document;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Finance\FinanceWrite;
use App\Finance\Settlement\SettlementInput;
use App\Models\Finance\ExternalDocument;
use App\Models\User;

/**
 * M-40-T03 (P-44): the linked document needs a correction (wrong amount, number or file); the reason is kept and the
 * file stays. The accounting system issues the corrected document, which is attached again (T04).
 */
final class T03RequestDocumentCorrection
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, ExternalDocument $document, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): ExternalDocument
    {
        $reason = SettlementInput::reason($reason);
        $this->write->run('document.correction', $actor, $idempotencyKey, ['document' => $document->id, 'reason' => $reason, 'lock_version' => $lockVersion], function () use ($actor, $document, $reason, $lockVersion, $source) {
            [$order, $locked] = DocumentStep::lock($actor, $document, $lockVersion, 'T03');
            DocumentStep::move($this->transitions, $locked, 'T03', ['correction_reason' => $reason, 'reviewed_by' => $actor->id, 'reviewed_at' => now()], $actor, $source, $reason);
            Audit::record(AuditAction::ExternalDocumentCorrectionRequested, $order, $actor, after: ['document' => $locked->id], reason: $reason, source: $source, label: $order->number);

            return ['document_id' => $locked->id];
        });

        return $document->refresh();
    }
}
