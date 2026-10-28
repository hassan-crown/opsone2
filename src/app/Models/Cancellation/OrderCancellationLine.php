<?php

namespace App\Models\Cancellation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A quantity an accepted cancellation took off an order line (or an agreement line). Written once; the effective
 * ordered quantity of a line is its quantity minus these (Cancellation\CancelledQuantities).
 *
 * @property int $id
 * @property int $order_cancellation_id
 * @property int $order_id
 * @property int|null $order_line_id
 * @property int|null $agreement_line
 * @property string $description
 * @property int $quantity_milli
 * @property int|null $value_minor
 * @property-read OrderCancellation $cancellation
 */
class OrderCancellationLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A cancelled quantity is never changed.'));
        static::deleting(fn () => throw new LogicException('A cancelled quantity is never deleted.'));
    }

    /**
     * @return BelongsTo<OrderCancellation, $this>
     */
    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(OrderCancellation::class, 'order_cancellation_id');
    }

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'value_minor' => 'integer',
            'agreement_line' => 'integer',
        ];
    }
}
