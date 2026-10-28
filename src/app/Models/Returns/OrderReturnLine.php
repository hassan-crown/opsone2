<?php

namespace App\Models\Returns;

use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivered order line asked back: requested, accepted (the reserved eligibility) and received quantities in
 * thousandths, and the line's unit value (halalas per unit, VAT-inclusive as sold) fixed at the request.
 *
 * @property int $id
 * @property int $order_return_id
 * @property int $order_line_id
 * @property int $requested_milli
 * @property int $accepted_milli
 * @property int $received_milli
 * @property int|null $unit_value_minor
 * @property-read OrderReturn $orderReturn
 * @property-read OrderLine $orderLine
 */
class OrderReturnLine extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function outstandingMilli(): int
    {
        return max(0, $this->accepted_milli - $this->received_milli);
    }

    protected function casts(): array
    {
        return [
            'requested_milli' => 'integer',
            'accepted_milli' => 'integer',
            'received_milli' => 'integer',
            'unit_value_minor' => 'integer',
        ];
    }
}
