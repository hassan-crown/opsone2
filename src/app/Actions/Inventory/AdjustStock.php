<?php

namespace App\Actions\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Inventory\MovementKind;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\User;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use App\Workflow\WorkGuard;
use Illuminate\Validation\ValidationException;

/**
 * P-49 (FUL-04): correct a balance with a reason as an adjustment movement (`SA-YYYY-000001`) — never a silent edit,
 * never below what is reserved, and never negative.
 */
final class AdjustStock
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly GaplessSequence $sequence,
    ) {}

    public function handle(User $actor, StockLocation $location, string $itemType, int $itemId, string $direction, int $quantityMilli, string $reason, ?int $countId = null, string $source = TransitionRecorder::SOURCE_WEB): StockMovement
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $location, $itemType, $itemId, $direction, $quantityMilli, $reason, $countId, $source) {
            $location = StockLocation::query()->lockForUpdate()->findOrFail($location->id);
            Access::authorize($actor, Permission::P49, $location);

            return $this->post($actor, $location, $itemType, $itemId, $direction, $quantityMilli, $reason, $countId, $source);
        });
    }

    /**
     * The adjustment itself, for a caller that already authorized and holds the transaction (count approval).
     */
    public function post(User $actor, StockLocation $location, string $itemType, int $itemId, string $direction, int $quantityMilli, string $reason, ?int $countId, string $source): StockMovement
    {
        if (! $location->isActive()) {
            throw ValidationException::withMessages(['location_id' => __('stock.validation.location_inactive')]);
        }
        $kind = match ($direction) {
            'in' => MovementKind::AdjustmentIn,
            'out' => MovementKind::AdjustmentOut,
            default => throw ValidationException::withMessages(['direction' => __('stock.validation.direction')]),
        };
        $item = StockItems::resolve($itemType, $itemId);
        $balance = $this->ledger->lock($location->id, $item->id);
        $before = $balance->on_hand_milli;
        $number = $this->sequence->format('stock_adjustments', 'SA', 6);
        $movement = $this->ledger->post($kind, $balance, $quantityMilli, [
            'document_number' => $number, 'count_id' => $countId, 'reason' => $reason, 'actor_id' => $actor->id,
        ]);
        Audit::record(AuditAction::StockAdjusted, $location, $actor, before: ['on_hand' => Quantity::format($before)], after: [
            'number' => $number, 'item_id' => $item->id, 'direction' => $direction, 'quantity' => Quantity::format($quantityMilli),
            'on_hand' => Quantity::format($balance->on_hand_milli),
        ], reason: $reason, source: $source, label: $number);

        return $movement;
    }
}
