<?php

namespace App\Models\Inventory;

use App\Access\Contracts\ScopedRecord;
use App\Inventory\ReservationStatus;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M-24: quantity held at a location for an order (a confirmed ready order line, or a custom order's material). The
 * remaining part is quantity − issued − released; issuing takes on-hand and reserved together once.
 *
 * @property int $id
 * @property string $number
 * @property int $location_id
 * @property int $stock_item_id
 * @property int|null $order_id
 * @property int|null $order_line_id
 * @property string $purpose
 * @property int $quantity_milli
 * @property int $issued_milli
 * @property int $released_milli
 * @property ReservationStatus $status
 * @property int|null $created_by
 * @property int $lock_version
 * @property-read StockLocation $location
 * @property-read StockItem $item
 * @property-read Order|null $order
 * @property-read OrderLine|null $orderLine
 */
class StockReservation extends Model implements ScopedRecord
{
    public const READY_ORDER = 'ready_order';

    public const CUSTOM_ORDER = 'custom_order';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'issued_milli' => 0, 'released_milli' => 0, 'status' => 'active'];

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function remainingMilli(): int
    {
        return $this->quantity_milli - $this->issued_milli - $this->released_milli;
    }

    /**
     * The location's branch and the order's branch: a branch grant must cover both.
     */
    public function accessBranchIds(): array
    {
        $location = $this->location;
        if ($location->branch_id === null) {
            return [];
        }
        $ids = [(int) $location->branch_id];
        if ($this->order !== null) {
            $ids[] = (int) $this->order->branch_id;
        }

        return array_values(array_unique($ids));
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return [];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'quantity_milli' => 'integer',
            'issued_milli' => 'integer',
            'released_milli' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
