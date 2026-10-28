<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $count_id
 * @property int $stock_item_id
 * @property int $expected_milli
 * @property int $counted_milli
 * @property-read StockItem $item
 */
class StockCountLine extends Model
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
        return ['expected_milli' => 'integer', 'counted_milli' => 'integer'];
    }
}
