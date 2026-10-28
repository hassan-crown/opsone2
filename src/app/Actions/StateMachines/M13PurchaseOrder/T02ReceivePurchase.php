<?php

namespace App\Actions\StateMachines\M13PurchaseOrder;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Inventory\Contracts\StockReceiver;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Procurement\PurchaseReceipt;
use App\Models\Procurement\PurchaseReceiptLine;
use App\Models\User;
use App\Procurement\Locations;
use App\Procurement\ProcurementWrite;
use App\Procurement\PurchaseStep;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * M-13-T02 (P-47, FUL-06): record one arrival from the supplier. Each line states what actually arrived split into
 * accepted and rejected (damaged) quantities. Only the accepted part satisfies the order and enters the stock — once,
 * through StockReceiver inside this transaction, under the key `po-receipt:{receipt}:{line}` — while the rejected part
 * is kept with its reason and the remainder is not reduced by it. An accepted quantity above the remainder is refused:
 * a larger supply is first agreed explicitly (T04). The same idempotency key replays the first result.
 */
final class T02ReceivePurchase
{
    public function __construct(
        private readonly ProcurementWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly StockReceiver $stock,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @param  array{location_id: int, received_on: string, reference?: string|null, note?: string|null, lines: list<array{line_id: int, accepted_milli: int, rejected_milli: int, reject_reason?: string|null}>}  $data
     * @param  list<UploadedFile>  $proofs
     */
    public function handle(User $actor, PurchaseOrder $po, array $data, array $proofs, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): PurchaseReceipt
    {
        $payload = [...$data, 'po' => $po->id, 'lock_version' => $lockVersion, 'proofs' => array_map(fn (UploadedFile $f) => hash_file('sha256', (string) $f->getRealPath()), $proofs)];

        $result = $this->write->run('po.receive', $actor, $idempotencyKey, $payload, function () use ($actor, $po, $data, $proofs, $lockVersion, $source) {
            $locked = PurchaseStep::lock($actor, $po, Permission::P47, $lockVersion, 'T02');
            // The goods land where the receiver may receive (P-47 on the location), never another branch's store.
            Locations::check($actor, $data['location_id'], $locked->branch_id, Permission::P47);
            $byId = $locked->lines->keyBy('id');
            $arrivals = $this->arrivals($data['lines'], $byId);

            $receipt = PurchaseReceipt::query()->create([
                'purchase_order_id' => $locked->id, 'location_id' => $data['location_id'], 'received_on' => $data['received_on'],
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'received_by' => $actor->id,
            ]);
            $receipt->setRelation('purchaseOrder', $locked);

            $context = [];
            foreach ($arrivals as $arrival) {
                $line = $byId[$arrival['line_id']];
                PurchaseReceiptLine::query()->create([
                    'purchase_receipt_id' => $receipt->id, 'purchase_order_line_id' => $line->id,
                    'received_milli' => $arrival['accepted_milli'] + $arrival['rejected_milli'],
                    'accepted_milli' => $arrival['accepted_milli'], 'rejected_milli' => $arrival['rejected_milli'],
                    'reject_reason' => $arrival['rejected_milli'] > 0 ? $arrival['reject_reason'] : null,
                ]);
                $line->forceFill([
                    'received_milli' => $line->received_milli + $arrival['accepted_milli'] + $arrival['rejected_milli'],
                    'accepted_milli' => $line->accepted_milli + $arrival['accepted_milli'],
                    'rejected_milli' => $line->rejected_milli + $arrival['rejected_milli'],
                ])->save();

                if ($arrival['accepted_milli'] > 0 && $line->tracksStock()) {
                    $this->stock->receive(
                        $data['location_id'], $line->item_type, $line->item_id, $arrival['accepted_milli'],
                        'purchase_receipt', $receipt->id, $actor->id, 'po-receipt:'.$receipt->id.':'.$line->id,
                    );
                }
                $context[] = ['line' => $line->line_no, 'accepted_milli' => $arrival['accepted_milli'], 'rejected_milli' => $arrival['rejected_milli'], 'remaining_milli' => $line->remainingMilli()];
            }

            foreach ($proofs as $proof) {
                $this->files->store($actor, $receipt, $proof, 'delivery_proof');
            }

            $to = PurchaseStep::derive($byId);
            $from = PurchaseStep::move($locked, $to);
            $this->transitions->record(StateMachine::M13, 'T02', $locked, $from, $to, $actor, $source, null, ['receipt' => $receipt->id, 'lines' => $context]);
            Audit::record(AuditAction::PurchaseReceived, $locked, $actor, after: ['number' => $locked->number, 'receipt' => $receipt->id, 'lines' => $context],
                source: $source, branchIds: [$locked->branch_id], label: $locked->number);

            return ['receipt_id' => $receipt->id, 'purchase_order_id' => $locked->id];
        });

        return PurchaseReceipt::query()->findOrFail((int) $result['result']['receipt_id']);
    }

    /**
     * Keeps the lines that brought something, checking each against the order's remainder.
     *
     * @param  list<array{line_id: int, accepted_milli: int, rejected_milli: int, reject_reason?: string|null}>  $input
     * @param  Collection<int, PurchaseOrderLine>  $byId
     * @return list<array{line_id: int, accepted_milli: int, rejected_milli: int, reject_reason: string|null}>
     */
    private function arrivals(array $input, $byId): array
    {
        $arrivals = [];
        $seen = [];
        foreach ($input as $i => $row) {
            $field = 'lines.'.$i;
            $line = $byId->get((int) $row['line_id']);
            if ($line === null || isset($seen[$line->id])) {
                throw ValidationException::withMessages([$field.'.line_id' => __('procurement.error.line_unknown')]);
            }
            $seen[$line->id] = true;
            $accepted = (int) $row['accepted_milli'];
            $rejected = (int) $row['rejected_milli'];
            if ($accepted < 0 || $rejected < 0) {
                throw ValidationException::withMessages([$field.'.accepted' => __('procurement.error.quantity')]);
            }
            if ($accepted + $rejected === 0) {
                continue;
            }
            if ($accepted > $line->remainingMilli()) {
                throw ValidationException::withMessages([$field.'.accepted' => __('procurement.error.over_receipt')]);
            }
            $reason = isset($row['reject_reason']) ? trim((string) $row['reject_reason']) : '';
            if ($rejected > 0 && $reason === '') {
                throw ValidationException::withMessages([$field.'.reject_reason' => __('procurement.error.reject_reason_required')]);
            }
            $arrivals[] = ['line_id' => $line->id, 'accepted_milli' => $accepted, 'rejected_milli' => $rejected, 'reject_reason' => $reason === '' ? null : $reason];
        }
        if ($arrivals === []) {
            throw ValidationException::withMessages(['lines' => __('procurement.error.nothing_received')]);
        }

        return $arrivals;
    }
}
