<?php

namespace App\Models\Returns;

use App\Models\Inventory\StockLocation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical receipt of returned goods at a location (M-15-T01/T07), inspected once (T02): good goes back to the
 * location's stock, damaged stays in custody outside the available quantity, rejected is handed back.
 *
 * @property int $id
 * @property int $order_return_id
 * @property int $order_return_line_id
 * @property int $location_id
 * @property int $quantity_milli
 * @property int|null $received_by
 * @property CarbonInterface $received_at
 * @property int|null $good_milli
 * @property int|null $damaged_milli
 * @property int|null $rejected_milli
 * @property string|null $inspection_note
 * @property int|null $inspected_by
 * @property CarbonInterface|null $inspected_at
 * @property-read OrderReturnLine $line
 * @property-read StockLocation $location
 */
class OrderReturnPart extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<OrderReturnLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderReturnLine::class, 'order_return_line_id');
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isInspected(): bool
    {
        return $this->inspected_at !== null;
    }

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'good_milli' => 'integer',
            'damaged_milli' => 'integer',
            'rejected_milli' => 'integer',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
        ];
    }
}
