<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An estimated or final measurement of an order (COM-07): by line or by a temporary position before products are
 * fixed. An ordinary correction is saved in place and audited; «estimated» never satisfies «final».
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $order_line_id
 * @property int|null $task_id
 * @property string $location
 * @property string $name
 * @property string $value
 * @property string $unit
 * @property string $kind
 * @property CarbonInterface $measured_on
 * @property string|null $note
 * @property int|null $recorded_by
 * @property-read Order $order
 */
class OrderMeasurement extends Model
{
    public const UNITS = ['mm', 'cm', 'm', 'in', 'm2', 'pcs'];

    public const KINDS = ['estimated', 'final'];

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:3',
            'measured_on' => 'date',
        ];
    }
}
