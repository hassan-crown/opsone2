<?php

namespace App\Models\Cancellation;

use App\Access\Contracts\ScopedRecord;
use App\Enums\CancellationStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A cancellation request of an order (M-34, SRS-COM-14): full or partial, decided once by P-18. Once accepted it keeps
 * the impact that was reviewed and applied (`impact`, `impact_hash`) as the record of what the decision did. Access
 * follows the order.
 *
 * @property int $id
 * @property string $number
 * @property int $order_id
 * @property string $scope
 * @property CancellationStatus $status
 * @property string $source
 * @property string|null $channel
 * @property CarbonInterface $requested_on
 * @property string $reason
 * @property list<array{ref: string, quantity_milli: int}>|null $lines
 * @property int|null $retained_minor
 * @property string $order_status_before
 * @property array<string, mixed>|null $impact
 * @property string|null $impact_hash
 * @property string|null $decision_reason
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property int|null $active_key
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read User|null $requester
 * @property-read User|null $decider
 */
class OrderCancellation extends Model implements ScopedRecord
{
    public const FULL = 'full';

    public const PARTIAL = 'partial';

    public const SOURCE_ACCOUNT = 'customer_account';

    public const SOURCE_EXTERNAL = 'external';

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('A cancellation request is never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * @return HasMany<OrderCancellationLine, $this>
     */
    public function cancelledLines(): HasMany
    {
        return $this->hasMany(OrderCancellationLine::class);
    }

    public function isFull(): bool
    {
        return $this->scope === self::FULL;
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
            'status' => CancellationStatus::class,
            'requested_on' => 'date',
            'lines' => 'array',
            'impact' => 'array',
            'retained_minor' => 'integer',
            'decided_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
