<?php

namespace App\Models\Finance;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The finance head of an order: the version every money write checks (`lock_version`) and the agreement the
 * schedule was last built from. Figures are derived from the journal, never stored here.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $agreement_id
 * @property int|null $payment_plan_id the plan chosen before the agreement, or the one the schedule was copied from
 * @property string|null $payment_plan_key the plan's key at that time (the built-in fallback has no row)
 * @property int $lock_version
 * @property-read Order $order
 */
class OrderFinance extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'agreement_id' => 'integer', 'payment_plan_id' => 'integer'];
    }
}
