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
use App\Models\Inventory\StockReservation;
use App\Models\User;

/**
 * M-24-T02 (nothing issued → released) and M-24-T03 (partly issued → settled mixed): free the unissued remainder
 * only. What was issued or delivered never comes back to stock here — that is a return (P-53). Authorization is the
 * caller's (P-18 on cancellation, P-51 on a custom order's material); call inside a LockingTransaction.
 *
 * A partial cancellation (ADR-0020) passes `$quantityMilli` below the remainder: that part is released and the
 * reservation stays open for the rest (same state, the movement and the transition record the part).
 */
final class T02ReleaseReservation
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(StockReservation $reservation, ?User $actor, ?string $reason, string $source = TransitionRecorder::SOURCE_WEB, ?int $quantityMilli = null): StockReservation
    {
        StockLedger::assertTransaction();
        $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
        $code = $reservation->status === ReservationStatus::PartiallyIssued ? 'T03' : 'T02';
        if (! $reservation->status->isOpen()) {
            throw new InvalidTransition(StateMachine::M24, $code, 'not_from_'.$reservation->status->value);
        }
        $remaining = $reservation->remainingMilli();
        if ($quantityMilli !== null && ($quantityMilli <= 0 || $quantityMilli > $remaining)) {
            throw new InvalidTransition(StateMachine::M24, $code, 'quantity');
        }
        $part = $quantityMilli !== null && $quantityMilli < $remaining;
        if ($part) {
            $remaining = $quantityMilli;
        }
        $balance = $this->ledger->lock($reservation->location_id, $reservation->stock_item_id);
        $this->ledger->post(MovementKind::Release, $balance, $remaining, [
            'document_number' => $reservation->number, 'reservation_id' => $reservation->id, 'order_id' => $reservation->order_id,
            'reason' => $reason, 'actor_id' => $actor?->id,
        ]);

        $from = $reservation->status;
        $reservation->released_milli += $remaining;
        if (! $part) {
            $reservation->status = $code === 'T03' ? ReservationStatus::SettledMixed : ReservationStatus::Released;
        }
        $reservation->lock_version++;
        $reservation->save();

        $this->transitions->record(StateMachine::M24, $code, $reservation, $from, $reservation->status, $actor, $source, $reason, ['released_milli' => $remaining] + ($part ? ['partial' => true] : []));
        Audit::record(AuditAction::StockReleased, $reservation, $actor, before: ['status' => $from->value],
            after: ['status' => $reservation->status->value, 'released' => Quantity::format($remaining)], reason: $reason, source: $source, label: $reservation->number);

        return $reservation;
    }
}
