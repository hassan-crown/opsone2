<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $transfer_id
 * @property int $stock_item_id
 * @property int $quantity_milli
 * @property-read StockItem $item
 */
class StockTransferLine extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer'];
    }
}
