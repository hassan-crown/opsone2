<?php

namespace App\Models\Settlement;

use App\Access\Contracts\ScopedRecord;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One remaining action of a settlement follow-up: a refund to make through P6 M-12, stock released, a supplier order
 * to settle, a commission corrected. `auto` items are derived from their source; a person with the source action's
 * permission may resolve one otherwise (`manual`, with a note).
 *
 * @property int $id
 * @property int $settlement_followup_id
 * @property int $order_id
 * @property string $kind
 * @property string $source
 * @property string|null $ref_type
 * @property int|null $ref_id
 * @property string $item_key
 * @property array<string, mixed>|null $details
 * @property int|null $target_minor
 * @property string $status
 * @property string|null $resolution
 * @property string|null $note
 * @property int|null $resolved_by
 * @property CarbonInterface|null $resolved_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read SettlementFollowUp $followUp
 * @property-read Order $order
 * @property-read User|null $resolver
 */
class SettlementItem extends Model implements ScopedRecord
{
    public const REFUND = 'refund';

    public const STOCK_RELEASE = 'stock_release';

    public const SUPPLIER = 'supplier';

    public const COMMISSION = 'commission';

    public const RETURN = 'return';

    public const KINDS = [self::REFUND, self::STOCK_RELEASE, self::SUPPLIER, self::COMMISSION, self::RETURN];

    public const OPEN = 'open';

    public const DONE = 'done';

    public const DROPPED = 'dropped';

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('A settlement item is never deleted.'));
    }

    /**
     * @return BelongsTo<SettlementFollowUp, $this>
     */
    public function followUp(): BelongsTo
    {
        return $this->belongsTo(SettlementFollowUp::class, 'settlement_followup_id');
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
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
            'details' => 'array',
            'target_minor' => 'integer',
            'ref_id' => 'integer',
            'resolved_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
