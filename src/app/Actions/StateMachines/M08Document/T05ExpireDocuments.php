<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementDocument;
use App\Models\Order;
use App\Support\LockingTransaction;

/**
 * M-08-T05 (time service, `agreements:expire-documents`, daily): a quote awaiting the customer past its last valid
 * day expires. An approved document never expires, and a design has no period. Each document in its own transaction;
 * a run repeated the same day changes nothing.
 */
final class T05ExpireDocuments
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function handle(): int
    {
        $expired = 0;
        $ids = AgreementDocument::query()->where('status', DocumentStatus::AwaitingCustomer->value)
            ->whereNotNull('valid_until')->whereDate('valid_until', '<', now()->toDateString())->pluck('id');

        foreach ($ids as $id) {
            $expired += LockingTransaction::run(function () use ($id) {
                $document = AgreementDocument::query()->findOrFail((int) $id);
                // Same lock order as every agreement write; a closed order's open quote still expires.
                $order = Order::query()->lockForUpdate()->findOrFail($document->order_id);
                $document = AgreementDocument::query()->lockForUpdate()->findOrFail((int) $id);
                if ($document->status !== DocumentStatus::AwaitingCustomer || $document->valid_until === null || ! $document->valid_until->copy()->endOfDay()->isPast()) {
                    return 0;
                }
                $document->forceFill(['status' => DocumentStatus::Expired, 'lock_version' => $document->lock_version + 1])->save();
                $this->transitions->record(StateMachine::M08, 'T05', $document, DocumentStatus::AwaitingCustomer, DocumentStatus::Expired, null,
                    TransitionRecorder::SOURCE_SYSTEM, context: ['order_id' => $order->id, 'valid_until' => $document->valid_until->toDateString()]);
                Audit::record(AuditAction::DocumentExpired, $order, null, after: ['document' => $document->number],
                    source: TransitionRecorder::SOURCE_SYSTEM, label: $order->number);

                return 1;
            });
        }

        return $expired;
    }
}
