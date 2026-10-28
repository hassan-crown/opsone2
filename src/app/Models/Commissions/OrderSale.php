<?php

namespace App\Models\Commissions;

use App\Access\Contracts\ScopedRecord;
use App\Models\Finance\OrderScoped;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * «صاحب المبيعة» (SAL-11): one member per order, with the rate pinned when the agreement was first approved (SAL-12).
 * Owner and rate never change on the row: a correction (SAL-16) supersedes it with a new row and moves its accrual.
 * In the `own` scope the sale belongs to its member — a sales person sees their own commissions (SAL-15) — the
 * other scopes follow the order.
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property int $rate_bp
 * @property string $rate_source
 * @property bool $manual_lines_eligible
 * @property string $owner_source
 * @property int|null $agreement_id
 * @property string $status
 * @property int|null $replaces_id
 * @property CarbonInterface $pinned_at
 * @property-read Order $order
 * @property-read User $member
 */
class OrderSale extends Model implements ScopedRecord
{
    use OrderScoped {
        accessOwnerId as private orderOwnerId;
    }

    public const ACTIVE = 'active';

    public const SUPERSEDED = 'superseded';

    /** Only the status moves (active → superseded). */
    private const MUTABLE = ['status', 'active_key', 'updated_at'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $sale) {
            if (array_diff(array_keys($sale->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('A pinned sale keeps its owner and rate; correct it with a new sale row.');
            }
        });
        static::deleting(fn () => throw new LogicException('Sales are never deleted.'));
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
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<CommissionEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function accessOwnerId(): ?int
    {
        return $this->user_id;
    }

    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
            'manual_lines_eligible' => 'boolean',
            'pinned_at' => 'datetime',
        ];
    }
}
