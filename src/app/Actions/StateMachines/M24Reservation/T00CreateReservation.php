<?php

namespace App\Actions\StateMachines\M24Reservation;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Inventory\MovementKind;
use App\Inventory\Quantity;
use App\Inventory\ReservationStatus;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\GaplessSequence;
use Illuminate\Validation\ValidationException;

/**
 * M-24-T00: hold a quantity from what is available at a location for an order (`SR-YYYY-000001`). The balance row is
 * locked first, so two holds on the last unit cannot both succeed. Authorization is the caller's (P-15 on a ready
 * order's confirmation, P-51 for a custom order's material); call inside a LockingTransaction.
 */
final class T00CreateReservation
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly GaplessSequence $sequence,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(StockLocation $location, StockItem $item, int $quantityMilli, ?Order $order, ?OrderLine $line, string $purpose, ?User $actor, string $source = TransitionRecorder::SOURCE_WEB, string $field = 'quantity'): StockReservation
    {
        StockLedger::assertTransaction();
        if (! $location->isActive()) {
            throw ValidationException::withMessages(['location_id' => __('stock.validation.location_inactive')]);
        }
        $balance = $this->ledger->lock($location->id, $item->id);
        if ($balance->availableMilli() < $quantityMilli) {
            throw ValidationException::withMessages([$field => __('stock.validation.insufficient', ['available' => Quantity::format(max(0, $balance->availableMilli()))])]);
        }

        $reservation = StockReservation::query()->create([
            'number' => $this->sequence->format('stock_reservations', 'SR', 6),
            'location_id' => $location->id,
            'stock_item_id' => $item->id,
            'order_id' => $order?->id,
            'order_line_id' => $line?->id,
            'purpose' => $purpose,
            'quantity_milli' => $quantityMilli,
            'status' => ReservationStatus::Active,
            'created_by' => $actor?->id,
        ]);
        $this->ledger->post(MovementKind::Reserve, $balance, $quantityMilli, [
            'document_number' => $reservation->number, 'reservation_id' => $reservation->id, 'order_id' => $order?->id,
            'actor_id' => $actor?->id,
        ], $field);

        $this->transitions->record(StateMachine::M24, 'T00', $reservation, null, ReservationStatus::Active, $actor, $source, null, [
            'quantity_milli' => $quantityMilli, 'location_id' => $location->id, 'order_id' => $order?->id,
        ]);
        Audit::record(AuditAction::StockReserved, $reservation, $actor, after: [
            'number' => $reservation->number, 'item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => Quantity::format($quantityMilli), 'order' => $order?->number,
        ], source: $source, label: $reservation->number);

        return $reservation;
    }
}
