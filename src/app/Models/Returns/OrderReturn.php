<?php

namespace App\Models\Returns;

use App\Enums\ReturnStatus;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use App\Access\Contracts\ScopedRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ready-goods return (M-15). A ScopedRecord on its order: every grant (P-42, P-53, P-102, P-37) is checked against
 * the order's branch / assignee / department. Amounts (`value_minor`, `settlement_minor`, line values) are shown only
 * with P-37 on the order.
 *
 * @property int $id
 * @property string $number
 * @property int $order_id
 * @property ReturnStatus $status
 * @property string $reason
 * @property string $source
 * @property int $window_days
 * @property int|null $requested_by
 * @property string|null $decision_reason
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property int|null $value_minor
 * @property int|null $settlement_minor
 * @property int|null $refund_id
 * @property string|null $cancel_reason
 * @property int|null $cancelled_by
 * @property CarbonInterface|null $cancelled_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 */
class OrderReturn extends Model implements ScopedRecord
{
    protected $table = 'order_returns';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<OrderReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderReturnLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderReturnPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(OrderReturnPart::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function accessBranchIds(): array
    {
        return $this->order->accessBranchIds();
    }

    public function accessDepartmentId(): ?int
    {
        return $this->order->accessDepartmentId();
    }

    public function accessAssigneeIds(): array
    {
        return $this->order->accessAssigneeIds();
    }

    public function accessOwnerId(): ?int
    {
        return $this->order->accessOwnerId();
    }

    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'window_days' => 'integer',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'value_minor' => 'integer',
            'settlement_minor' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
