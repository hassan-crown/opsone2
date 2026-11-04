<?php

namespace App\Models\Automation;

use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A domain event the automation engine reacts to (ADR-0042), normalized from a state transition, an audit event or
 * the timer scan, and written in the transaction of the change. `dedupe_key` makes each source fact one event.
 *
 * @property int $id
 * @property string $trigger
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $order_id
 * @property string $source transition | audit | timer
 * @property int|null $source_id
 * @property string $dedupe_key
 * @property array<string, mixed>|null $context
 * @property list<string>|null $caused_by
 * @property int $depth
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $processed_at
 * @property int $attempts
 * @property-read Order|null $order
 */
class AutomationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'order_id' => 'integer',
            'source_id' => 'integer',
            'context' => 'array',
            'caused_by' => 'array',
            'depth' => 'integer',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
