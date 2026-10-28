<?php

namespace App\Models\Procurement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What one receipt brought for one order line: actually arrived = accepted + rejected, kept apart (FUL-06).
 *
 * @property int $id
 * @property int $purchase_receipt_id
 * @property int $purchase_order_line_id
 * @property int $received_milli
 * @property int $accepted_milli
 * @property int $rejected_milli
 * @property string|null $reject_reason
 * @property-read PurchaseOrderLine $orderLine
 */
class PurchaseReceiptLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Receipts are append-only.'));
        static::deleting(fn () => throw new LogicException('Receipts are append-only.'));
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    protected function casts(): array
    {
        return ['received_milli' => 'integer', 'accepted_milli' => 'integer', 'rejected_milli' => 'integer'];
    }
}
