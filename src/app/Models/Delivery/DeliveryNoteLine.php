<?php

namespace App\Models\Delivery;

use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantity (thousandths) of one order line handed over by a delivery note.
 *
 * @property int $id
 * @property int $delivery_note_id
 * @property int $order_line_id
 * @property int $quantity_milli
 * @property-read DeliveryNote $note
 * @property-read OrderLine $orderLine
 */
class DeliveryNoteLine extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<DeliveryNote, $this>
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id');
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer'];
    }
}
