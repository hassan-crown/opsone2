<?php

namespace App\Inventory;

use App\Inventory\Contracts\StockReceiver;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * The single write path of stock (ADR-0017). Every change is one appended movement plus the balance row it moves,
 * both inside the caller's transaction, with the balance row taken `SELECT … FOR UPDATE` first: concurrent issues,
 * reservations and transfers of the same item at the same location queue on that row, so on-hand, reserved and
 * available never go negative and two confirmations never hold the same last unit (FUL-01/02, INT-01).
 */
final class StockLedger implements StockReceiver
{
    public function __construct(private readonly LowStockAlerts $alerts) {}

    /**
     * The balance row of an item at a location, created at zero when missing and locked for this transaction.
     */
    public function lock(int $locationId, int $stockItemId): StockBalance
    {
        self::assertTransaction();
        // The location row first (shared), then the balance: disabling a location (FOR UPDATE on its row, then its
        // balances checked empty) and stock arriving there serialize, and the order location → balance is the one
        // every writer already follows (adjustments and counts lock the location first).
        StockLocation::query()->whereKey($locationId)->sharedLock()->value('id');
        $now = now();
        DB::table('stock_balances')->insertOrIgnore([
            'location_id' => $locationId, 'stock_item_id' => $stockItemId, 'on_hand_milli' => 0, 'reserved_milli' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return StockBalance::query()->where('location_id', $locationId)->where('stock_item_id', $stockItemId)
            ->lockForUpdate()->firstOrFail();
    }

    /**
     * Locks several balance rows in one global order (location, item) so two writers never wait on each other.
     *
     * @param  array<int, array{0: int, 1: int}>  $pairs  [location id, stock item id]
     * @return array<string, StockBalance> keyed "location:item"
     */
    public function lockMany(array $pairs): array
    {
        $pairs = array_values($pairs);
        usort($pairs, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $locked = [];
        foreach ($pairs as [$locationId, $itemId]) {
            $locked[$locationId.':'.$itemId] ??= $this->lock($locationId, $itemId);
        }

        return $locked;
    }

    /**
     * Appends one movement and moves the (already locked) balance. Refuses a non-positive quantity and any result
     * that would make on-hand, reserved or available negative — the whole transaction then rolls back.
     *
     * @param  array{document_number?: string|null, reservation_id?: int|null, transfer_id?: int|null, count_id?: int|null, order_id?: int|null, source_type?: string|null, source_id?: int|null, reason?: string|null, actor_id?: int|null, idempotency_key?: string|null}  $refs
     */
    public function post(MovementKind $kind, StockBalance $balance, int $quantityMilli, array $refs = [], string $field = 'quantity'): StockMovement
    {
        self::assertTransaction();
        if ($quantityMilli <= 0) {
            throw ValidationException::withMessages([$field => __('stock.validation.quantity_positive')]);
        }
        [$onHandSign, $reservedSign] = $kind->signs();
        $onHand = $balance->on_hand_milli + $onHandSign * $quantityMilli;
        $reserved = $balance->reserved_milli + $reservedSign * $quantityMilli;

        if ($kind->takesAvailable() && $balance->availableMilli() < $quantityMilli) {
            throw ValidationException::withMessages([$field => __('stock.validation.insufficient', ['available' => Quantity::format(max(0, $balance->availableMilli()))])]);
        }
        if ($onHand < 0 || $reserved < 0 || $reserved > $onHand) {
            throw ValidationException::withMessages([$field => __('stock.validation.insufficient', ['available' => Quantity::format(max(0, $balance->availableMilli()))])]);
        }

        // Nothing arrives at a disabled location (checked under the location's shared lock taken by lock()).
        if ($onHandSign > 0 && StockLocation::query()->whereKey($balance->location_id)->value('status') !== 'active') {
            throw ValidationException::withMessages(['location_id' => __('stock.validation.location_inactive')]);
        }
        $item = StockItem::query()->findOrFail($balance->stock_item_id);
        $balance->on_hand_milli = $onHand;
        $balance->reserved_milli = $reserved;
        $balance->save();

        $movement = StockMovement::query()->create([
            'kind' => $kind,
            'location_id' => $balance->location_id,
            'stock_item_id' => $balance->stock_item_id,
            'unit' => $item->unit->value,
            'quantity_milli' => $quantityMilli,
            'on_hand_delta' => $onHandSign * $quantityMilli,
            'reserved_delta' => $reservedSign * $quantityMilli,
            'on_hand_after' => $onHand,
            'reserved_after' => $reserved,
            'document_number' => $refs['document_number'] ?? null,
            'reservation_id' => $refs['reservation_id'] ?? null,
            'transfer_id' => $refs['transfer_id'] ?? null,
            'count_id' => $refs['count_id'] ?? null,
            'order_id' => $refs['order_id'] ?? null,
            'source_type' => $refs['source_type'] ?? null,
            'source_id' => $refs['source_id'] ?? null,
            'reason' => $refs['reason'] ?? null,
            'actor_id' => $refs['actor_id'] ?? null,
            'idempotency_key' => $refs['idempotency_key'] ?? null,
            'occurred_at' => now(),
        ]);

        if ($kind->takesAvailable()) {
            $this->alerts->check($item);
        }

        return $movement;
    }

    /**
     * Supplier receipt (P7 procurement seam). Authorization (P-47 on the location) is the caller's. A replay of the
     * same idempotency key changes nothing (FUL-03: a repeated receipt notice never adds to the balance).
     */
    public function receive(int $locationId, string $itemType, int $itemId, int $quantityMilli, string $sourceType, int $sourceId, ?int $actorId, string $idempotencyKey): void
    {
        self::assertTransaction();
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new InvalidArgumentException('A receipt needs an idempotency key of at most 120 characters.');
        }
        $location = StockLocation::query()->find($locationId);
        if ($location === null || ! $location->isActive()) {
            throw ValidationException::withMessages(['location_id' => __('stock.validation.location_inactive')]);
        }
        $item = StockItems::resolve($itemType, $itemId);
        $balance = $this->lock($locationId, $item->id);
        // Checked after taking the row lock, so a concurrent duplicate waits and then sees the first one.
        if (StockMovement::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $this->post(MovementKind::Receipt, $balance, $quantityMilli, [
            'source_type' => $sourceType, 'source_id' => $sourceId, 'actor_id' => $actorId, 'idempotency_key' => $idempotencyKey,
        ]);
    }

    public static function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock is written only inside the transaction that makes the change.');
        }
    }
}
