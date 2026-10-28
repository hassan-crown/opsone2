<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementSignals;
use App\Agreements\AgreementWrite;
use App\Agreements\ContentHash;
use App\Agreements\DiscountCheck;
use App\Agreements\DocumentApproval;
use App\Agreements\OrderAgreement;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DiscountDecision;
use App\Models\FinanceSettings;
use App\Models\User;
use App\Workflow\Transition;
use Illuminate\Validation\ValidationException;

/**
 * M-08-T01 (P-30, AGR-04): send a draft to the customer. Requires ready content (every price known), a discount within
 * the sender's limit or approved for exactly this content (AGR-02), a customer who can receive it and, for a quote,
 * a validity period. Fixes the content and its hash — what the customer approves. A draft that replaces an earlier
 * sent document marks it replaced (T04); its link no longer accepts a decision. Sending is not approval; a sent
 * quote or contract verifies the flow's «prepare a document» action (P5).
 */
final class T01SendDocument
{
    public const MAX_VALIDITY_DAYS = 365;

    public function __construct(
        private readonly AgreementWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly DocumentApproval $approval,
        private readonly AgreementSignals $signals,
    ) {}

    public function handle(User $actor, AgreementDocument $document, ?int $validityDays, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        if ($validityDays !== null && ($validityDays < 1 || $validityDays > self::MAX_VALIDITY_DAYS)) {
            throw ValidationException::withMessages(['validity_days' => __('agreements.validation.validity')]);
        }

        $payload = ['document_id' => $document->id, 'validity_days' => $validityDays, 'lock_version' => $lockVersion];
        $this->write->run('document.send', $actor, $idempotencyKey, $payload, function () use ($actor, $document, $validityDays, $lockVersion, $source) {
            [$order, $document] = AgreementLock::document($document, $lockVersion, 'T01');
            Access::authorize($actor, Permission::P30, $order);
            Transition::check(StateMachine::M08, DocumentStatus::transitions(), $document->status, 'T01');

            $content = $document->content;
            match ($document->kind) {
                DocumentKind::Quote => $this->checkQuote($actor, $document, $content),
                DocumentKind::Design, DocumentKind::Contract => ($content['source'] ?? null) === 'agreement' || isset($content['file'])
                    ? null : Transition::refuse(StateMachine::M08, 'T01', 'content_incomplete'),
            };
            if (trim($order->customer->mobile) === '') {
                Transition::refuse(StateMachine::M08, 'T01', 'no_recipient');
            }

            $previous = $document->replaces_id === null ? null : AgreementDocument::query()->lockForUpdate()->find($document->replaces_id);
            if ($previous !== null && $previous->status->isOpen()) {
                $this->approval->replace($order, $previous, 'T04', $actor, $source, ['replaced_by' => $document->id]);
            }

            $days = $validityDays ?? FinanceSettings::current()->quote_validity_days;
            $document->forceFill([
                'status' => DocumentStatus::AwaitingCustomer,
                'content_hash' => ContentHash::of($content),
                'valid_until' => $document->kind->expires() ? now()->addDays($days)->toDateString() : null,
                'sent_at' => now(),
                'sent_by' => $actor->id,
                'lock_version' => $document->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M08, 'T01', $document, DocumentStatus::Draft, DocumentStatus::AwaitingCustomer, $actor, $source, context: [
                'order_id' => $order->id, 'content_hash' => $document->content_hash, 'valid_until' => $document->valid_until?->toDateString(),
            ]);
            Audit::record(AuditAction::DocumentSent, $order, $actor, after: [
                'document' => $document->number, 'valid_until' => $document->valid_until?->toDateString(), 'total_minor' => $content['totals']['total_minor'] ?? null,
            ], source: $source, label: $order->number);

            $this->signals->sent($order, $document, $actor);

            return ['document_id' => $document->id];
        });

        return $document->refresh();
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function checkQuote(User $actor, AgreementDocument $document, array $content): void
    {
        if (OrderAgreement::current($document->order) !== null) {
            Transition::refuse(StateMachine::M08, 'T01', 'agreement_exists');
        }
        $state = DiscountCheck::state($actor, DiscountDecision::SUBJECT_DOCUMENT, $document->id, $content)['state'];
        match ($state) {
            DiscountCheck::INCOMPLETE => Transition::refuse(StateMachine::M08, 'T01', 'content_incomplete'),
            DiscountCheck::NEEDS_DECISION, DiscountCheck::REQUESTED => Transition::refuse(StateMachine::M08, 'T01', 'discount_needs_decision'),
            default => null,
        };
    }
}
