<?php

namespace App\Actions\StateMachines\M24Reservation;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Inventory\MovementKind;
use App\Inventory\Quantity;
use App\Inventory\ReservationStatus;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * M-24-T01: delivery (P-52) or consumption (P-51) of part of a reservation: on-hand and reserved fall together once.
 * The reservation row is re-read FOR UPDATE, so an issue racing a cancellation of the same remainder sees the
 * committed outcome of the other (INT-01). Idempotent per key when the caller passes one. Authorization is the
 * caller's; call inside a LockingTransaction.
 */
final class T01IssueReservation
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  array{source_type?: string|null, source_id?: int|null, document_number?: string|null, idempotency_key?: string|null, reason?: string|null}  $refs
     */
    public function handle(StockReservation $reservation, int $quantityMilli, ?User $actor, array $refs = [], string $source = TransitionRecorder::SOURCE_WEB): StockReservation
    {
        StockLedger::assertTransaction();
        $key = $refs['idempotency_key'] ?? null;
        $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
        if ($key !== null && StockMovement::query()->where('idempotency_key', $key)->exists()) {
            return $reservation;
        }
        if (! $reservation->status->isOpen()) {
            throw new InvalidTransition(StateMachine::M24, 'T01', 'not_from_'.$reservation->status->value);
        }
        if ($quantityMilli <= 0 || $quantityMilli > $reservation->remainingMilli()) {
            throw ValidationException::withMessages(['quantity' => __('stock.validation.over_reservation', ['remaining' => Quantity::format($reservation->remainingMilli())])]);
        }

        $balance = $this->ledger->lock($reservation->location_id, $reservation->stock_item_id);
        $this->ledger->post(MovementKind::IssueReserved, $balance, $quantityMilli, [
            'document_number' => $refs['document_number'] ?? $reservation->number,
            'reservation_id' => $reservation->id,
            'order_id' => $reservation->order_id,
            'source_type' => $refs['source_type'] ?? null,
            'source_id' => $refs['source_id'] ?? null,
            'reason' => $refs['reason'] ?? null,
            'actor_id' => $actor?->id,
            'idempotency_key' => $key,
        ]);

        $from = $reservation->status;
        $reservation->issued_milli += $quantityMilli;
        $reservation->status = $reservation->remainingMilli() === 0 ? ReservationStatus::Issued : ReservationStatus::PartiallyIssued;
        $reservation->lock_version++;
        $reservation->save();

        $this->transitions->record(StateMachine::M24, 'T01', $reservation, $from, $reservation->status, $actor, $source, null, [
            'quantity_milli' => $quantityMilli, 'source_type' => $refs['source_type'] ?? null, 'source_id' => $refs['source_id'] ?? null,
        ]);
        Audit::record(AuditAction::StockIssued, $reservation, $actor, before: ['issued' => Quantity::format($reservation->issued_milli - $quantityMilli)],
            after: ['issued' => Quantity::format($reservation->issued_milli), 'status' => $reservation->status->value], source: $source, label: $reservation->number);

        return $reservation;
    }
}
