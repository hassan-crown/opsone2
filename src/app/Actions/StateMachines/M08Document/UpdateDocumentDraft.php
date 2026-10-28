<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\Access;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\DiscountCheck;
use App\Agreements\QuoteInput;
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
use Illuminate\Support\Facades\Validator;

/**
 * Free correction of a draft with a change log (domain 04): the quote's lines, prices, discount, terms and execution
 * days (P-29), or a design/contract draft's description and terms (their preparing permission). Not a transition;
 * a sent document is never edited — revise it instead. Changing priced content supersedes earlier discount
 * decisions (M-26-T04) so an approval never carries over to different numbers.
 */
final class UpdateDocumentDraft
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly DiscountCheck $discounts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, AgreementDocument $document, array $data, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        $this->write->run('document.update', $actor, $idempotencyKey, ['document_id' => $document->id, 'lock_version' => $lockVersion, 'data' => $data],
            function () use ($actor, $document, $data, $lockVersion, $source) {
                [$order, $document] = AgreementLock::document($document, $lockVersion, 'draft');
                Access::authorize($actor, $document->kind->preparePermission(), $document);
                if ($document->status !== DocumentStatus::Draft) {
                    Transition::refuse(StateMachine::M08, 'draft', 'not_draft');
                }

                $before = $document->content;
                if ($document->kind === DocumentKind::Quote) {
                    $content = QuoteInput::priced(QuoteInput::normalize($order, $data), FinanceSettings::current());
                    $this->discounts->contentChanged(DiscountDecision::SUBJECT_DOCUMENT, $document->id, $content, $actor);
                } else {
                    $fields = Validator::make($data, ['description' => ['nullable', 'string', 'max:2000'], 'terms' => ['nullable', 'string', 'max:5000']])->validate();
                    $content = [...$document->content, ...array_intersect_key($fields, array_flip(['description', 'terms']))];
                }

                $document->forceFill(['content' => $content, 'lock_version' => $document->lock_version + 1])->save();
                Audit::record(AuditAction::DocumentDraftUpdated, $order, $actor,
                    before: ['document' => $document->number, 'totals' => $before['totals'] ?? null, 'lines' => count($before['lines'] ?? [])],
                    after: ['totals' => $content['totals'] ?? null, 'lines' => count($content['lines'] ?? [])],
                    source: $source, label: $order->number);

                return ['document_id' => $document->id];
            });

        return $document->refresh();
    }
}
