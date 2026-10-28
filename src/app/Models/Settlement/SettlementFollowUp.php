<?php

namespace App\Models\Settlement;

use App\Access\Contracts\ScopedRecord;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The one settlement follow-up of an order (SRS-INT-08, FIN-14): the remaining actions after a cancellation or a
 * return, each followed from its source. It closes only when every item is done or dropped; a later item reopens it.
 * Written through App\Settlement\SettlementFollowUps only.
 *
 * @property int $id
 * @property int $order_id
 * @property string $status
 * @property CarbonInterface|null $closed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 */
class SettlementFollowUp extends Model implements ScopedRecord
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'settlement_followups';

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('A settlement follow-up is never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<SettlementItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class, 'settlement_followup_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
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
            'closed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
