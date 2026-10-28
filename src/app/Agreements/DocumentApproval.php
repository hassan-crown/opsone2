<?php

namespace App\Agreements;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\Order;
use App\Models\User;
use App\Workflow\Transition;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * M-08-T02/T03 shared by the two ways a customer decides: from their own account (CustomerDecisions, P8 portal) and
 * an external approval recorded by staff (T02RecordExternalApproval). The decision names the content hash the
 * customer saw; a replaced, expired or changed document is refused before any write (AGR-04).
 */
final class DocumentApproval
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly AgreementActivation $activation,
        private readonly AgreementSignals $signals,
    ) {}

    /**
     * Preconditions of T02/T03 on a locked document.
     */
    public static function ensureDecidable(AgreementDocument $document, string $contentHash, string $code): void
    {
        Transition::check(StateMachine::M08, DocumentStatus::transitions(), $document->status, $code);
        if (! hash_equals((string) $document->content_hash, $contentHash)) {
            Transition::refuse(StateMachine::M08, $code, 'content_changed');
        }
        // Validity ends at the end of its last day; the timed expiry (T05) may not have run yet.
        if ($document->valid_until !== null && $document->valid_until->copy()->endOfDay()->isPast()) {
            Transition::refuse(StateMachine::M08, $code, 'expired');
        }
        if ($code === 'T02' && $document->kind === DocumentKind::Quote
            && OrderAgreement::current($document->order) !== null) {
            Transition::refuse(StateMachine::M08, $code, 'agreement_exists');
        }
    }

    /**
     * @param  array{method: string, channel?: string|null, decided_at: CarbonInterface, recorded_by?: int|null, customer_mobile?: string|null, note?: string|null}  $how
     */
    public function approve(Order $order, AgreementDocument $document, array $how, ?User $actor, string $source): CustomerDecision
    {
        $decision = $this->decision($order, $document, CustomerDecision::APPROVED, $how);
        $from = $document->status;
        $document->forceFill(['status' => DocumentStatus::Approved, 'decided_at' => now(), 'lock_version' => $document->lock_version + 1])->save();
        $this->transitions->record(StateMachine::M08, 'T02', $document, $from, DocumentStatus::Approved, $actor, $source, context: [
            'order_id' => $order->id, 'decision_id' => $decision->id, 'method' => $how['method'], 'channel' => $how['channel'] ?? null,
            'content_hash' => $document->content_hash,
        ]);
        Audit::record(AuditAction::DocumentApproved, $order, $actor, after: [
            'document' => $document->number, 'method' => $how['method'], 'channel' => $how['channel'] ?? null,
        ], source: $source, label: $order->number);

        // An approved document this one replaces stays as proof, marked replaced (M-08-T07).
        $previous = $document->replaces_id === null ? null : AgreementDocument::query()->lockForUpdate()->find($document->replaces_id);
        if ($previous !== null && $previous->status === DocumentStatus::Approved) {
            $this->replace($order, $previous, 'T07', $actor, $source, ['replaced_by' => $document->id]);
        }

        if ($document->kind === DocumentKind::Quote) {
            $this->activation->activate($order, $document->content, 'document', $document->id, $decision, $actor, $source);
        }

        $this->signals->approved($order, $document, $actor);

        return $decision;
    }

    /**
     * @param  array{method: string, decided_at: CarbonInterface, customer_mobile?: string|null, note?: string|null}  $how
     */
    public function requestChanges(Order $order, AgreementDocument $document, array $how, ?User $actor, string $source): CustomerDecision
    {
        $decision = $this->decision($order, $document, CustomerDecision::CHANGES_REQUESTED, $how);
        $from = $document->status;
        $document->forceFill(['status' => DocumentStatus::ChangesRequested, 'decided_at' => now(), 'lock_version' => $document->lock_version + 1])->save();
        $this->transitions->record(StateMachine::M08, 'T03', $document, $from, DocumentStatus::ChangesRequested, $actor, $source, $how['note'] ?? null, [
            'order_id' => $order->id, 'decision_id' => $decision->id,
        ]);
        Audit::record(AuditAction::DocumentChangesRequested, $order, $actor, after: ['document' => $document->number, 'note' => $how['note'] ?? null],
            source: $source, label: $order->number);

        return $decision;
    }

    /**
     * M-08-T04 / T07: the document is kept as proof; its link no longer accepts a decision.
     *
     * @param  array<string, mixed>  $context
     */
    public function replace(Order $order, AgreementDocument $document, string $code, ?User $actor, string $source, array $context = []): void
    {
        $from = $document->status;
        $to = Transition::check(StateMachine::M08, DocumentStatus::transitions(), $from, $code);
        $document->forceFill(['status' => $to, 'lock_version' => $document->lock_version + 1])->save();
        $this->transitions->record(StateMachine::M08, $code, $document, $from, DocumentStatus::Replaced, $actor, $source, context: ['order_id' => $order->id, ...$context]);
        Audit::record(AuditAction::DocumentReplaced, $order, $actor, before: ['document' => $document->number, 'status' => $from->value],
            after: ['status' => DocumentStatus::Replaced->value, ...$context], source: $source, label: $order->number);
    }

    /**
     * @param  array{method: string, channel?: string|null, decided_at: CarbonInterface, recorded_by?: int|null, customer_mobile?: string|null, note?: string|null}  $how
     */
    private function decision(Order $order, AgreementDocument $document, string $decision, array $how): CustomerDecision
    {
        try {
            return CustomerDecision::query()->create([
                'subject_type' => 'document',
                'subject_id' => $document->id,
                'order_id' => $order->id,
                'content_hash' => (string) $document->content_hash,
                'decision' => $decision,
                'method' => $how['method'],
                'channel' => $how['channel'] ?? null,
                'decided_at' => $how['decided_at'],
                'recorded_by' => $how['recorded_by'] ?? null,
                'customer_id' => $order->customer_id,
                'customer_mobile' => $how['customer_mobile'] ?? null,
                'note' => $how['note'] ?? null,
                'approval_key' => $decision === CustomerDecision::APPROVED ? 'document:'.$document->id : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidTransition(StateMachine::M08, 'T02', 'already_approved');
        }
    }
}
