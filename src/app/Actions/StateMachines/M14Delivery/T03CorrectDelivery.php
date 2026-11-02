<?php

namespace App\Actions\StateMachines\M14Delivery;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Delivery\DeliveryCorrected;
use App\Delivery\DeliveryCorrections;
use App\Delivery\DeliveryProgress;
use App\Delivery\DeliveryWrite;
use App\Enums\DeliveryStatus;
use App\Enums\ReadyOrderStatus;
use App\Enums\StateMachine;
use App\Inventory\MovementKind;
use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Inventory\ReservationStatus;
use App\Inventory\StockLedger;
use App\Models\Delivery\DeliveryCorrection;
use App\Models\Delivery\DeliveryCorrectionLine;
use App\Models\Delivery\DeliveryNote;
use App\Models\Delivery\DeliveryNoteLine;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\User;
use App\Returns\ReturnEligibility;
use App\Support\StaleRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * M-14-T03 (P-52, + P-49 on each stock location that moves; ADR-0034): correct the delivered quantity of a confirmed
 * delivery with a reason. The note and its lines stay as recorded; a correction row takes quantity back per line.
 * For ready goods the person chooses where it goes:
 * - `reservation`: back into the customer's reservation it was issued from (on hand and reserved rise together), so the
 *   order owes it again and a delivered order returns to partially delivered;
 * - `stock`: back into the general stock (on hand rises, the reservation's part is released), so the order no longer
 *   owes it; any price consequence is settled by cancellation / refund, never here.
 * A made-to-order line moves no stock (FUL-10); the quantity becomes owed again. Each stock movement is the exact
 * counterpart of the issue it corrects (same reservation, location and item), newest reservation first. Under the
 * order lock taken first; a correction never goes below what returns already hold, and one idempotency key runs once.
 * The «first delivery» date is not moved (the current due rule stays); the event and the date seen are recorded for a
 * future rule engine.
 */
final class T03CorrectDelivery
{
    public function __construct(
        private readonly DeliveryWrite $write,
        private readonly StockLedger $ledger,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  array{lines: list<array{line_id: int|string, quantity: string}>, destination?: string|null, reason: string}  $data
     */
    public function handle(User $actor, DeliveryNote $note, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): DeliveryCorrection
    {
        $reason = trim($data['reason']);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => __('delivery.correction.reason_invalid')]);
        }
        $destination = $data['destination'] ?? null;
        if ($destination !== null && ! in_array($destination, [DeliveryCorrection::TO_STOCK, DeliveryCorrection::TO_RESERVATION], true)) {
            throw ValidationException::withMessages(['destination' => __('delivery.correction.destination_required')]);
        }
        $wanted = [];
        foreach ($data['lines'] as $i => $row) {
            try {
                $milli = Quantity::parse((string) $row['quantity']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => __('delivery.correction.quantity_invalid')]);
            }
            if ($milli > 0) {
                $wanted[(int) $row['line_id']] = ($wanted[(int) $row['line_id']] ?? 0) + $milli;
            }
        }
        if ($wanted === []) {
            throw ValidationException::withMessages(['lines' => __('delivery.correction.nothing')]);
        }

        $payload = ['note_id' => $note->id, 'lines' => $wanted, 'destination' => $destination, 'reason' => $reason, 'lock_version' => $lockVersion];
        $result = $this->write->run('correct', $actor, $idempotencyKey, $payload, function () use ($actor, $note, $wanted, $destination, $reason, $lockVersion, $source) {
            // The order row first (INT-01): deliveries, returns and cancellations of this order queue on it.
            $order = OrderStock::lockOrder($note->order_id);
            $locked = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);
            $locked->setRelation('order', $order);
            Access::authorize($actor, Permission::P52, $locked);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            if ($locked->status !== DeliveryStatus::Confirmed) {
                throw new InvalidTransition(StateMachine::M14, 'T03', 'not_from_'.$locked->status->value);
            }
            if (! DeliveryCorrections::orderAccepts($order)) {
                throw new InvalidTransition(StateMachine::M14, 'T03', 'order_'.$order->status);
            }

            $lines = $locked->lines()->get()->keyBy('id');
            $corrected = DeliveryCorrections::correctedByNoteLine($locked->id);
            $held = $order->isReady() ? ReturnEligibility::heldByLine($order->id) : [];
            $delivered = DeliveryProgress::deliveredByLine($order->id);
            foreach ($wanted as $lineId => $milli) {
                /** @var DeliveryNoteLine|null $line */
                $line = $lines->get($lineId);
                if ($line === null) {
                    throw ValidationException::withMessages(['lines' => __('delivery.correction.unknown_line')]);
                }
                $left = $line->quantity_milli - ($corrected[$line->id] ?? 0);
                if ($order->isReady()) {
                    // What returns already hold of this order line stays delivered.
                    $left = min($left, ($delivered[$line->order_line_id] ?? 0) - ($held[$line->order_line_id] ?? 0));
                }
                if ($milli > $left) {
                    throw ValidationException::withMessages(['lines' => __('delivery.correction.over', ['remaining' => Quantity::format(max(0, $left))])]);
                }
            }

            $plan = $order->isReady() ? $this->stockPlan($locked, $lines, $wanted) : [];
            if ($plan !== [] && $destination === null) {
                throw ValidationException::withMessages(['destination' => __('delivery.correction.destination_required')]);
            }
            if ($plan === []) {
                $destination = null;
            }
            // Stock moves only with P-49 over each location it reaches.
            foreach (array_unique(array_map(fn (array $p) => $p[0]->location_id, $plan)) as $locationId) {
                Access::authorize($actor, Permission::P49, StockLocation::query()->findOrFail($locationId));
            }

            $correction = DeliveryCorrection::query()->create([
                'delivery_note_id' => $locked->id, 'order_id' => $order->id, 'destination' => $destination, 'reason' => $reason,
                'first_delivery_on' => DeliveryCorrections::firstDeliveryOn($order->id), 'created_by' => $actor->id,
            ]);

            if ($plan !== []) {
                $this->ledger->lockMany(array_map(fn (array $p) => [$p[0]->location_id, $p[0]->stock_item_id], $plan));
                foreach ($plan as [$reservation, $take, $line]) {
                    $this->giveBack($actor, $order, $correction, $reservation, $take, (string) $destination, $reason, $source);
                    DeliveryCorrectionLine::query()->create([
                        'delivery_correction_id' => $correction->id, 'delivery_note_line_id' => $line->id,
                        'order_line_id' => $line->order_line_id, 'stock_reservation_id' => $reservation->id, 'quantity_milli' => $take,
                    ]);
                }
            } else {
                foreach ($wanted as $lineId => $milli) {
                    /** @var DeliveryNoteLine $line */
                    $line = $lines->get($lineId);
                    DeliveryCorrectionLine::query()->create([
                        'delivery_correction_id' => $correction->id, 'delivery_note_line_id' => $lineId,
                        'order_line_id' => $line->order_line_id, 'stock_reservation_id' => null, 'quantity_milli' => $milli,
                    ]);
                }
            }

            $orderFrom = $order->state();
            if ($order->isReady() && $orderFrom === ReadyOrderStatus::Delivered && collect(DeliveryProgress::lines($order))->contains(fn (array $r) => $r['deliverable'] > 0)) {
                // The order owes goods again: it goes back to partially delivered (the target T08 chooses among).
                $order->forceFill(['status' => ReadyOrderStatus::PartiallyDelivered->value, 'lock_version' => $order->lock_version + 1])->save();
                $this->transitions->record($order->kind->machine(), 'T08', $order, $orderFrom, ReadyOrderStatus::PartiallyDelivered, $actor, $source, $reason, ['delivery_correction' => $correction->id]);
            }

            $summary = collect($wanted)->map(fn (int $m, int $id) => ['order_line_id' => $lines->get($id)?->order_line_id, 'quantity' => Quantity::format($m)])->values()->all();
            $locked->forceFill(['lock_version' => $locked->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M14, 'T03', $locked, DeliveryStatus::Confirmed, DeliveryStatus::Confirmed, $actor, $source, $reason, [
                'correction' => $correction->id, 'destination' => $destination, 'lines' => $summary,
            ]);
            Audit::record(AuditAction::DeliveryCorrected, $locked, $actor, before: ['delivered' => collect($wanted)->map(fn (int $m, int $id) => Quantity::format(($lines->get($id)->quantity_milli ?? 0) - ($corrected[$id] ?? 0)))->all()], after: [
                'correction' => $correction->id, 'order' => $order->number, 'destination' => $destination, 'lines' => $summary,
                'first_delivery_on' => $correction->first_delivery_on?->toDateString(),
            ], reason: $reason, source: $source, branchIds: [$order->branch_id], label: (string) $locked->number);

            $orderId = $order->id;
            $correctionId = $correction->id;
            DB::afterCommit(fn () => event(new DeliveryCorrected($orderId, $correctionId)));

            return ['correction_id' => $correction->id];
        });

        return DeliveryCorrection::query()->findOrFail((int) $result['result']['correction_id']);
    }

    /**
     * Which reservation issues of this note each corrected quantity comes from: the newest reservation first, never
     * more than the note issued from it minus what earlier corrections already took back.
     *
     * @param  Collection<int, DeliveryNoteLine>  $lines
     * @param  array<int, int>  $wanted  note line id → quantity
     * @return list<array{0: StockReservation, 1: int, 2: DeliveryNoteLine}>
     */
    private function stockPlan(DeliveryNote $note, $lines, array $wanted): array
    {
        $plan = [];
        foreach ($wanted as $lineId => $milli) {
            /** @var DeliveryNoteLine $line */
            $line = $lines->get($lineId);
            $left = $milli;
            foreach (DeliveryCorrections::issuedPieces($note->id, $line) as $reservationId => $available) {
                if ($left === 0) {
                    break;
                }
                $take = min($left, $available);
                if ($take > 0) {
                    $plan[] = [StockReservation::query()->lockForUpdate()->findOrFail($reservationId), $take, $line];
                    $left -= $take;
                }
            }
            if ($left > 0) {
                throw ValidationException::withMessages(['lines' => __('delivery.correction.over', ['remaining' => Quantity::format($milli - $left)])]);
            }
        }

        return $plan;
    }

    /**
     * The counter-movement of an issue: on hand comes back at the reservation's location, either held again for the
     * order line (`reservation`) or free (`stock`, the reservation records it as released).
     */
    private function giveBack(User $actor, Order $order, DeliveryCorrection $correction, StockReservation $reservation, int $take, string $destination, string $reason, string $source): void
    {
        $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
        $toReservation = $destination === DeliveryCorrection::TO_RESERVATION;
        $balance = $this->ledger->lock($reservation->location_id, $reservation->stock_item_id);
        $this->ledger->post($toReservation ? MovementKind::CorrectionReserve : MovementKind::CorrectionIn, $balance, $take, [
            'document_number' => $reservation->number, 'reservation_id' => $reservation->id, 'order_id' => $order->id,
            'source_type' => 'delivery_correction', 'source_id' => $correction->id, 'reason' => $reason, 'actor_id' => $actor->id,
            'idempotency_key' => 'delivery-correction:'.$correction->id.':'.$reservation->id,
        ]);

        $from = $reservation->status;
        $reservation->issued_milli -= $take;
        if (! $toReservation) {
            $reservation->released_milli += $take;
        }
        $reservation->status = match (true) {
            $reservation->remainingMilli() > 0 => $reservation->issued_milli > 0 ? ReservationStatus::PartiallyIssued : ReservationStatus::Active,
            $reservation->issued_milli === 0 => ReservationStatus::Released,
            $reservation->released_milli > 0 => ReservationStatus::SettledMixed,
            default => ReservationStatus::Issued,
        };
        $reservation->lock_version++;
        $reservation->save();

        Audit::record(AuditAction::StockCorrectionReturned, $reservation, $actor, before: ['status' => $from->value, 'issued' => Quantity::format($reservation->issued_milli + $take)], after: [
            'status' => $reservation->status->value, 'issued' => Quantity::format($reservation->issued_milli), 'returned' => Quantity::format($take),
            'destination' => $destination, 'delivery_correction' => $correction->id,
        ], reason: $reason, source: $source, label: $reservation->number);
    }
}
