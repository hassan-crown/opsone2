<?php

namespace App\Models;

use App\Enums\OrderLineType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One order line with its specification frozen at add time (COM-11): the catalog version it came from, the payload
 * of that version, chosen options, quantity and price. A line is never updated or deleted; the only change allowed
 * is marking it removed, which keeps it in the history.
 *
 * @property int $id
 * @property int $order_id
 * @property int $position
 * @property OrderLineType $line_type
 * @property int|null $catalog_version_id
 * @property string|null $catalog_item_type
 * @property int|null $catalog_item_id
 * @property array<string, mixed> $snapshot
 * @property string|null $description
 * @property string $quantity
 * @property string|null $unit
 * @property int|null $unit_price_minor
 * @property int|null $line_total_minor
 * @property int|null $added_by
 * @property CarbonInterface|null $removed_at
 * @property int|null $removed_by
 * @property-read Order $order
 */
class OrderLine extends Model
{
    /** Columns that may change after the line is written (removal only). */
    private const MUTABLE = ['removed_at', 'removed_by', 'updated_at'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            if (array_diff(array_keys($line->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('An order line is immutable once added; remove it and add a new one.');
            }
        });
        static::deleting(fn () => throw new LogicException('An order line is never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    protected function casts(): array
    {
        return [
            'line_type' => OrderLineType::class,
            'snapshot' => 'array',
            'quantity' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'removed_at' => 'datetime',
        ];
    }
}
