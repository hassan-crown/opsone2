<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Balance of one item at one location: the projection of the journal written in the same transaction as each
 * movement, under `SELECT … FOR UPDATE` (StockLedger). Available = on hand − reserved; neither is ever negative.
 *
 * @property int $id
 * @property int $location_id
 * @property int $stock_item_id
 * @property int $on_hand_milli
 * @property int $reserved_milli
 * @property-read StockLocation $location
 * @property-read StockItem $item
 */
class StockBalance extends Model
{
    protected $guarded = ['id'];

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

    public function availableMilli(): int
    {
        return $this->on_hand_milli - $this->reserved_milli;
    }

    protected function casts(): array
    {
        return ['on_hand_milli' => 'integer', 'reserved_milli' => 'integer'];
    }
}
