<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\Numbers;
use App\Agreements\OrderAgreement;
use App\Agreements\QuoteInput;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementDocument;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Models\User;
use App\Workflow\Transition;

/**
 * M-08 creation (P-29, AGR-01): a quote draft inside the order, filled from its active lines (the product reference
 * price as a suggestion). A draft creates no debt and no order. The first agreement comes from a quote; after it,
 * value changes go through an agreement change (M-09), so a second quote is refused while one is open or agreed.
 */
final class T00CreateQuote
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly Numbers $numbers,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, Order $order, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        $result = $this->write->run('quote.create', $actor, $idempotencyKey, ['order_id' => $order->id], function () use ($actor, $order, $source) {
            $order = AgreementLock::order($order->id, StateMachine::M08, 'T00');
            Access::authorize($actor, Permission::P29, $order);
            if (OrderAgreement::current($order) !== null) {
                Transition::refuse(StateMachine::M08, 'T00', 'agreement_exists');
            }
            $open = AgreementDocument::query()->where('order_id', $order->id)->where('kind', DocumentKind::Quote->value)
                ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::AwaitingCustomer->value, DocumentStatus::ChangesRequested->value, DocumentStatus::Expired->value])
                ->exists();
            if ($open) {
                Transition::refuse(StateMachine::M08, 'T00', 'quote_open');
            }

            $settings = FinanceSettings::current();
            $content = QuoteInput::priced([
                'lines' => QuoteInput::fromOrder($order),
                'discount' => ['type' => 'none', 'value' => 0],
                'terms' => null,
                'execution_days' => null,
            ], $settings);

            $document = AgreementDocument::query()->create([
                'order_id' => $order->id,
                'kind' => DocumentKind::Quote,
                'number' => $this->numbers->document(DocumentKind::Quote),
                'status' => DocumentStatus::Draft,
                'content' => $content,
                'created_by' => $actor->id,
            ]);
            $this->transitions->record(StateMachine::M08, 'T00', $document, null, DocumentStatus::Draft, $actor, $source, context: ['order_id' => $order->id]);
            Audit::record(AuditAction::DocumentCreated, $order, $actor, after: ['document' => $document->number, 'kind' => 'quote'], source: $source, label: $order->number);

            return ['document_id' => $document->id];
        });

        return AgreementDocument::query()->findOrFail((int) $result['result']['document_id']);
    }
}
