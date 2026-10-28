<?php

namespace App\Actions\StateMachines\M14Delivery;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\Inventory\IssueReadyReservation;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Delivery\DeliveryConfirmed;
use App\Delivery\DeliveryProgress;
use App\Delivery\DeliveryWrite;
use App\Enums\DeliveryStatus;
use App\Enums\ReadyOrderStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Inventory\OrderStock;
use App\Inventory\StockLedger;
use App\Models\Delivery\DeliveryNote;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Support\GaplessSequence;
use App\Support\StaleRecord;
use App\Workflow\ActionResults;
use App\Workflow\Catalogue\ActionType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M-14-T01 (P-52, FUL-09/FUL-10, Q-44 a): record the delivery. In one transaction, under the order row lock taken
 * first: the quantities are re-checked against what is still deliverable (never above the reservation / the effective
 * ordered quantity), ready lines issue their reservations once through `IssueReadyReservation` (key
 * `delivery:{note}:{line}:{reservation}`), the gapless `DN-YYYY-000123` is taken, the receiver and proof (at least one
 * private file) are stored and a ready order moves to partially delivered / delivered (M-05-T08). A complete delivery
 * then reports the P5 action result once through `ActionResults::verifyFromSource`. The same idempotency key replays the
 * first result; confirming a confirmed note is refused.
 */
final class T01ConfirmDelivery
{
    public function __construct(
        private readonly DeliveryWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly IssueReadyReservation $issue,
        private readonly StockLedger $ledger,
        private readonly GaplessSequence $numbers,
        private readonly AttachmentService $files,
        private readonly ActionResults $results,
    ) {}

    /**
     * @param  array{delivered_on: string, receiver_name: string, note?: string|null}  $data
     * @param  list<UploadedFile>  $proofs
     */
    public function handle(User $actor, DeliveryNote $note, array $data, array $proofs, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): DeliveryNote
    {
        $payload = [...$data, 'note_id' => $note->id, 'lock_version' => $lockVersion, 'proofs' => array_map(fn (UploadedFile $f) => hash_file('sha256', (string) $f->getRealPath()), $proofs)];

        $result = $this->write->run('confirm', $actor, $idempotencyKey, $payload, function () use ($actor, $note, $data, $proofs, $lockVersion, $source) {
            // The order row first (INT-01): a cancellation, another delivery and this one queue on it.
            $order = OrderStock::lockOrder($note->order_id);
            $locked = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);
            $locked->setRelation('order', $order);
            Access::authorize($actor, Permission::P52, $locked);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            if ($locked->status !== DeliveryStatus::Draft) {
                throw new InvalidTransition(StateMachine::M14, 'T01', 'not_from_'.$locked->status->value);
            }
            if (! DeliveryProgress::accepts($order)) {
                throw new InvalidTransition(StateMachine::M14, 'T01', 'order_'.$order->status);
            }
            $receiver = trim($data['receiver_name']);
            if ($receiver === '') {
                throw ValidationException::withMessages(['receiver_name' => __('delivery.error.receiver_required')]);
            }
            if ($proofs === []) {
                throw ValidationException::withMessages(['proofs' => __('delivery.error.proof_required')]);
            }

            $rows = DeliveryProgress::lines($order);
            $lines = $locked->lines()->get();
            foreach ($lines as $line) {
                $row = $rows[$line->order_line_id] ?? null;
                if ($row === null || $line->quantity_milli > $row['deliverable']) {
                    throw ValidationException::withMessages(['lines' => __('delivery.error.over_deliverable', ['remaining' => \App\Inventory\Quantity::format($row['deliverable'] ?? 0)])]);
                }
            }

            $firstDelivery = ! DeliveryNote::query()->where('order_id', $order->id)->where('status', DeliveryStatus::Confirmed->value)->exists();
            $fromOrder = $order->state();
            if ($order->isReady()) {
                OrderTransitions::check($order, 'T08');
                $this->issueReady($actor, $order, $locked, $lines, $source);
            }

            $number = $this->numbers->format('delivery_note', 'DN', 6);
            $locked->forceFill([
                'number' => $number, 'status' => DeliveryStatus::Confirmed, 'delivered_on' => $data['delivered_on'], 'receiver_name' => $receiver,
                'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : $locked->note,
                'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'lock_version' => $locked->lock_version + 1,
            ])->save();
            foreach ($proofs as $proof) {
                $this->files->store($actor, $locked, $proof, 'delivery_proof');
            }

            $complete = DeliveryProgress::complete($order);
            if ($order->isReady()) {
                $to = collect(DeliveryProgress::lines($order))->every(fn (array $r) => $r['deliverable'] === 0) ? ReadyOrderStatus::Delivered : ReadyOrderStatus::PartiallyDelivered;
                $order->forceFill(['status' => $to->value, 'lock_version' => $order->lock_version + 1])->save();
                $this->transitions->record($order->kind->machine(), 'T08', $order, $fromOrder, $to, $actor, $source, null, ['delivery' => $number]);
            }

            $this->transitions->record(StateMachine::M14, 'T01', $locked, DeliveryStatus::Draft, DeliveryStatus::Confirmed, $actor, $source, null, [
                'number' => $number, 'order' => $order->number, 'complete' => $complete,
                'lines' => $lines->map(fn ($l) => ['order_line_id' => $l->order_line_id, 'quantity_milli' => $l->quantity_milli])->all(),
            ]);
            Audit::record(AuditAction::DeliveryConfirmed, $locked, $actor, after: [
                'number' => $number, 'order' => $order->number, 'receiver' => $receiver, 'delivered_on' => (string) $data['delivered_on'], 'complete' => $complete,
            ], source: $source, branchIds: [$order->branch_id], label: $number);

            if ($complete && ! $order->isReady()) {
                // Only made-to-order orders run a flow. Idempotent on the source: the first complete delivery completes the P5
                // action once.
                $this->results->verifyFromSource($order, ActionType::RecordDelivery, null, 'delivery_note', $locked->id, $actor);
            }

            $orderId = $order->id;
            $noteId = $locked->id;
            DB::afterCommit(fn () => event(new DeliveryConfirmed($orderId, $noteId, $firstDelivery, $complete)));

            return ['note_id' => $locked->id];
        });

        return DeliveryNote::query()->findOrFail((int) $result['result']['note_id']);
    }

    /**
     * Issues every ready line's quantity from its open reservations, oldest reservation first, locking the balances in
     * the global order before the first movement.
     *
     * @param  Collection<int, \App\Models\Delivery\DeliveryNoteLine>  $lines
     */
    private function issueReady(User $actor, Order $order, DeliveryNote $note, $lines, string $source): void
    {
        $reservations = StockReservation::query()->where('order_id', $order->id)->orderBy('id')->get()->groupBy('order_line_id');
        $plan = [];
        foreach ($lines as $line) {
            $left = $line->quantity_milli;
            /** @var Collection<int, StockReservation> $held */
            $held = $reservations->get($line->order_line_id) ?? new Collection;
            foreach ($held as $reservation) {
                if ($left === 0) {
                    break;
                }
                if (! $reservation->status->isOpen() || $reservation->remainingMilli() === 0) {
                    continue;
                }
                $take = min($left, $reservation->remainingMilli());
                $plan[] = [$reservation, $take, $line->order_line_id];
                $left -= $take;
            }
            if ($left > 0) {
                throw ValidationException::withMessages(['lines' => __('delivery.error.over_deliverable', ['remaining' => \App\Inventory\Quantity::format($line->quantity_milli - $left)])]);
            }
        }
        $this->ledger->lockMany(array_map(fn (array $p) => [$p[0]->location_id, $p[0]->stock_item_id], $plan));
        foreach ($plan as [$reservation, $take, $lineId]) {
            $this->issue->handle($actor, $order, $reservation, $take, 'delivery_note', $note->id, 'delivery:'.$note->id.':'.$lineId.':'.$reservation->id, $source);
        }
    }
}
