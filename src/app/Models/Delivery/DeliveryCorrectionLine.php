<?php

namespace App\Models\Delivery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One piece of a delivery correction: the quantity taken back from a note line (and, for ready goods, from which
 * reservation's issue).
 *
 * @property int $id
 * @property int $delivery_correction_id
 * @property int $delivery_note_line_id
 * @property int $order_line_id
 * @property int|null $stock_reservation_id
 * @property int $quantity_milli
 * @property-read DeliveryCorrection $correction
 */
class DeliveryCorrectionLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Delivery corrections are append-only.'));
        static::deleting(fn () => throw new LogicException('Delivery corrections are append-only.'));
    }

    /** @return BelongsTo<DeliveryCorrection, $this> */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(DeliveryCorrection::class, 'delivery_correction_id');
    }

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer'];
    }
}
