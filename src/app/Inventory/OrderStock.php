<?php

namespace App\Inventory;

use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\StaleRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Stock seen from an order: which locations may serve it, its reservations, and the order row lock that every stock
 * write on an order takes first (the first read of its transaction), so a cancellation and a delivery or issue on the
 * same order queue instead of interleaving (INT-01).
 */
final class OrderStock
{
    public static function lockOrder(int $orderId, ?int $expectedVersion = null): Order
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Order stock writes run inside a transaction.');
        }
        $order = Order::query()->lockForUpdate()->find($orderId) ?? throw new LogicException('The order disappeared.');
        if ($expectedVersion !== null && $order->lock_version !== $expectedVersion) {
            throw new StaleRecord($order->lock_version);
        }

        return $order;
    }

    /**
     * Active locations that may serve an order: those of its branch and facility-wide ones. Choosing a branch never
     * opens another branch's stock (domain 06).
     *
     * @return Collection<int, StockLocation>
     */
    public static function locationsFor(Order $order): Collection
    {
        return StockLocation::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $order->branch_id))
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')->orderByDesc('is_default')->orderBy('id')->get();
    }

    public static function usableFor(Order $order, ?int $locationId, string $field = 'location_id'): StockLocation
    {
        $location = $locationId === null
            ? self::locationsFor($order)->first()
            : self::locationsFor($order)->firstWhere('id', $locationId);
        if ($location === null) {
            throw ValidationException::withMessages([$field => __('stock.validation.location_unusable')]);
        }

        return $location;
    }

    /**
     * @return Collection<int, StockReservation>
     */
    public static function reservations(Order $order): Collection
    {
        return StockReservation::query()->where('order_id', $order->id)->with(['location', 'item'])->orderBy('id')->get();
    }

    /**
     * Ready lines of an order with what is reserved, issued and released for each, and what the order's usable
     * locations still have available (shown on the order before and after confirmation).
     *
     * @return list<array<string, mixed>>
     */
    public static function readyLines(Order $order): array
    {
        $reservations = self::reservations($order)->groupBy('order_line_id');
        $locationIds = self::locationsFor($order)->pluck('id')->all();
        $rows = [];
        foreach ($order->activeLines()->get() as $line) {
            /** @var OrderLine $line */
            if ($line->catalog_item_id === null || $line->line_type->value !== 'ready_variant') {
                continue;
            }
            $item = StockItems::find('ready_variant', (int) $line->catalog_item_id);
            $available = $item === null ? 0 : (int) StockBalance::query()->where('stock_item_id', $item->id)
                ->whereIn('location_id', $locationIds)->sum(DB::raw('on_hand_milli - reserved_milli'));
            /** @var Collection<int, StockReservation> $held */
            $held = $reservations->get($line->id) ?? new Collection;
            $rows[] = [
                'line_id' => $line->id,
                'quantity' => Quantity::format(Quantity::parse($line->quantity)),
                'available' => Quantity::format(max(0, $available)),
                'short' => Quantity::format(max(0, Quantity::parse($line->quantity) - max(0, $available))),
                'reserved' => Quantity::format((int) $held->sum(fn (StockReservation $r) => $r->status->isOpen() ? $r->remainingMilli() : 0)),
                'issued' => Quantity::format((int) $held->sum('issued_milli')),
                'released' => Quantity::format((int) $held->sum('released_milli')),
                'reservations' => $held->map(fn (StockReservation $r) => self::reservation($r))->values()->all(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function reservation(StockReservation $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status->value,
            'location' => ['id' => $r->location->id, 'code' => $r->location->code, 'name' => (string) $r->location->name->get(app()->getLocale()), 'branch_id' => $r->location->branch_id],
            'item' => StockItems::describe($r->item),
            'quantity' => Quantity::format($r->quantity_milli),
            'issued' => Quantity::format($r->issued_milli),
            'released' => Quantity::format($r->released_milli),
            'remaining' => Quantity::format($r->remainingMilli()),
        ];
    }

    /**
     * Reserved stock for the branch-transfer impact (COM-12, replaces the P4 placeholder): open reservations stay at
     * their locations; those whose location is in another branch than the target are counted so the screen warns.
     *
     * @return array{available: true, applicable: bool, reserved_lines: list<array<string, mixed>>, outside_target: int}
     */
    public static function transferImpact(Order $order, int $targetBranchId): array
    {
        $open = self::reservations($order)->filter(fn (StockReservation $r) => $r->status->isOpen())->values();

        return [
            'available' => true,
            'applicable' => $open->isNotEmpty() || $order->isReady(),
            'reserved_lines' => array_values($open->map(fn (StockReservation $r) => self::reservation($r) + [
                'stays_outside_target' => $r->location->branch_id !== null && (int) $r->location->branch_id !== $targetBranchId,
            ])->all()),
            'outside_target' => $open->filter(fn (StockReservation $r) => $r->location->branch_id !== null && (int) $r->location->branch_id !== $targetBranchId)->count(),
        ];
    }
}
