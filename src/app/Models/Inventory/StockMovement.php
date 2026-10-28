<?php

namespace App\Models\Inventory;

use App\Inventory\MovementKind;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the append-only stock journal (FUL-04): kind, location, item, unit, positive quantity, signed effect on
 * on-hand and reserved, references (document, reservation, transfer, count, order, source), reason, actor and time.
 * Never updated or deleted.
 *
 * @property int $id
 * @property MovementKind $kind
 * @property int $location_id
 * @property int $stock_item_id
 * @property string $unit
 * @property int $quantity_milli
 * @property int $on_hand_delta
 * @property int $reserved_delta
 * @property int $on_hand_after
 * @property int $reserved_after
 * @property string|null $document_number
 * @property int|null $reservation_id
 * @property int|null $transfer_id
 * @property int|null $count_id
 * @property int|null $order_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $reason
 * @property int|null $actor_id
 * @property string|null $idempotency_key
 * @property CarbonInterface $occurred_at
 * @property-read StockLocation $location
 * @property-read StockItem $item
 * @property-read User|null $actor
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A stock movement is never changed; post an opposite movement.'));
        static::deleting(fn () => throw new LogicException('A stock movement is never deleted.'));
    }

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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => MovementKind::class,
            'quantity_milli' => 'integer',
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
            'on_hand_after' => 'integer',
            'reserved_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
