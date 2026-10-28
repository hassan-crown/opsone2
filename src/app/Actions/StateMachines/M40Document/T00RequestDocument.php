<?php

namespace App\Actions\StateMachines\M40Document;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ExternalDocumentStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceWrite;
use App\Models\Finance\ExternalDocument;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * M-40 request (P-44): a document the external accounting system must issue for an order event («مستند مطلوب»). It
 * appears on FinInvoices until the file is linked. OpsOne never issues it.
 */
final class T00RequestDocument
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {kind, event, payment_id?, refund_id?, note?}
     */
    public function handle(User $actor, Order $order, array $data, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): ExternalDocument
    {
        $data = Validator::make($data, DocumentLinks::rules())->validate();
        $result = $this->write->run('document.request', $actor, $idempotencyKey, [...$data, 'order_id' => $order->id], function () use ($actor, $order, $data, $source) {
            $locked = DocumentStep::order($actor, $order->id, 'T00');
            $links = DocumentLinks::resolve($locked, $data);
            $document = new ExternalDocument([...$links, 'order_id' => $locked->id, 'requested_by' => $actor->id]);
            $document->setRelation('order', $locked);
            $document->forceFill(['status' => ExternalDocumentStatus::Requested, 'lock_version' => 1])->save();
            $this->transitions->record(StateMachine::M40, 'T00', $document, null, $document->status, $actor, $source, $links['note']);
            Audit::record(AuditAction::ExternalDocumentRequested, $locked, $actor, after: ['document' => $document->id, 'kind' => $links['kind']->value, 'event' => $links['event']->value], source: $source, label: $locked->number);

            return ['document_id' => $document->id];
        });

        return ExternalDocument::query()->findOrFail((int) $result['result']['document_id']);
    }
}
