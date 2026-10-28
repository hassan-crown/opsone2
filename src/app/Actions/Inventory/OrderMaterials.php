<?php

namespace App\Actions\Inventory;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M24Reservation\T00CreateReservation;
use App\Actions\StateMachines\M24Reservation\T01IssueReservation;
use App\Actions\StateMachines\M24Reservation\T02ReleaseReservation;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\CustomOrderStatus;
use App\Enums\OrderKind;
use App\Enums\StateMachine;
use App\Inventory\MovementKind;
use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\User;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use Illuminate\Validation\ValidationException;

/**
 * P-51 (FUL-03): manual reserve / issue / release of a tracked material for a made-to-order order — no consumption is
 * ever computed from dimensions. The actor needs P-51 on both the order and the location; the order must be open and
 * not waiting on a cancellation (INT-01: nothing new and irreversible while cancellation is pending).
 */
final class OrderMaterials
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly GaplessSequence $sequence,
        private readonly T00CreateReservation $reserve,
        private readonly T01IssueReservation $issueReserved,
        private readonly T02ReleaseReservation $release,
    ) {}

    public function reserve(User $actor, Order $order, int $locationId, int $materialId, int $quantityMilli, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): StockReservation
    {
        return LockingTransaction::run(function () use ($actor, $order, $locationId, $materialId, $quantityMilli, $lockVersion, $source) {
            [$order, $location] = $this->open($actor, $order, $locationId, $lockVersion);
            $item = StockItems::resolve(StockItem::MATERIAL, $materialId, 'material_id');
            $this->ledger->lock($location->id, $item->id);
            $reservation = $this->reserve->handle($location, $item, $quantityMilli, $order, null, StockReservation::CUSTOM_ORDER, $actor, $source);
            $this->touch($order);

            return $reservation;
        });
    }

    /**
     * Issue on the order: from one of its reservations, or directly from what is available (`SI-YYYY-000001`).
     * Idempotent per key.
     */
    public function issue(User $actor, Order $order, int $locationId, int $materialId, int $quantityMilli, ?int $reservationId, string $idempotencyKey, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): StockMovement
    {
        return LockingTransaction::run(function () use ($actor, $order, $locationId, $materialId, $quantityMilli, $reservationId, $idempotencyKey, $lockVersion, $source) {
            $key = 'material-issue:'.$order->id.':'.$idempotencyKey;
            $done = StockMovement::query()->where('idempotency_key', $key)->first();
            if ($done !== null) {
                return $done;
            }
            [$order, $location] = $this->open($actor, $order, $locationId, $lockVersion);
            $item = StockItems::resolve(StockItem::MATERIAL, $materialId, 'material_id');

            if ($reservationId !== null) {
                $reservation = StockReservation::query()->where('order_id', $order->id)->where('location_id', $location->id)
                    ->where('stock_item_id', $item->id)->find($reservationId)
                    ?? throw ValidationException::withMessages(['reservation_id' => __('stock.validation.reservation_mismatch')]);
                $this->issueReserved->handle($reservation, $quantityMilli, $actor, ['source_type' => 'custom_order', 'source_id' => $order->id, 'idempotency_key' => $key], $source);
                $this->touch($order);

                return StockMovement::query()->where('idempotency_key', $key)->firstOrFail();
            }

            $balance = $this->ledger->lock($location->id, $item->id);
            // A concurrent replay waited on the order lock; it now sees the first one.
            $done = StockMovement::query()->where('idempotency_key', $key)->first();
            if ($done !== null) {
                return $done;
            }
            $number = $this->sequence->format('stock_issues', 'SI', 6);
            $movement = $this->ledger->post(MovementKind::Issue, $balance, $quantityMilli, [
                'document_number' => $number, 'order_id' => $order->id, 'source_type' => 'custom_order', 'source_id' => $order->id,
                'actor_id' => $actor->id, 'idempotency_key' => $key,
            ]);
            Audit::record(AuditAction::StockIssued, $location, $actor, after: [
                'number' => $number, 'order' => $order->number, 'item_id' => $item->id, 'quantity' => Quantity::format($quantityMilli),
            ], source: $source, label: $number);
            $this->touch($order);

            return $movement;
        });
    }

    public function release(User $actor, Order $order, StockReservation $reservation, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): StockReservation
    {
        return LockingTransaction::run(function () use ($actor, $order, $reservation, $reason, $lockVersion, $source) {
            if ($reservation->order_id !== $order->id) {
                abort(404);
            }
            [$order] = $this->open($actor, $order, $reservation->location_id, $lockVersion, allowPending: true);
            $released = $this->release->handle($reservation, $actor, $reason, $source);
            $this->touch($order);

            return $released;
        });
    }

    /**
     * @return array{0: Order, 1: StockLocation}
     */
    private function open(User $actor, Order $order, int $locationId, ?int $lockVersion, bool $allowPending = false): array
    {
        $order = OrderStock::lockOrder($order->id, $lockVersion);
        if ($order->kind !== OrderKind::Custom) {
            throw ValidationException::withMessages(['order' => __('stock.validation.custom_only')]);
        }
        Access::authorize($actor, Permission::P51, $order);
        $state = $order->state();
        if ($state->isFinal() || (! $allowPending && $state === CustomOrderStatus::CancellationPending)) {
            throw new InvalidTransition(StateMachine::M24, 'T00', 'order_'.$order->status);
        }
        $location = StockLocation::query()->find($locationId);
        if ($location === null || ! $location->isActive()) {
            throw ValidationException::withMessages(['location_id' => __('stock.validation.location_inactive')]);
        }
        Access::authorize($actor, Permission::P51, $location);

        return [$order, $location];
    }

    private function touch(Order $order): void
    {
        Order::query()->whereKey($order->id)->increment('lock_version');
        $order->lock_version++;
    }
}
