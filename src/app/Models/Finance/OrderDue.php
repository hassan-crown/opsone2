<?php

namespace App\Models\Finance;

use App\Enums\DueBasis;
use App\Enums\DueEvent;
use App\Models\Order;
use App\Money\Money;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One installment of the order's schedule (FIN-02). Its amount follows the active agreement; a due that money was
 * allocated to is removed only by replacing the schedule with one that keeps at least that amount on it.
 *
 * @property int $id
 * @property int $order_id
 * @property int $position
 * @property string $reference
 * @property Translated $name
 * @property DueBasis $basis
 * @property int|null $basis_value
 * @property int $amount_minor
 * @property DueEvent $due_event
 * @property CarbonInterface|null $due_date
 * @property CarbonInterface|null $removed_at
 * @property-read Order $order
 */
class OrderDue extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor);
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'basis' => DueBasis::class,
            'basis_value' => 'integer',
            'amount_minor' => 'integer',
            'due_event' => DueEvent::class,
            'due_date' => 'date',
            'removed_at' => 'datetime',
            'position' => 'integer',
        ];
    }
}
