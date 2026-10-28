<?php

namespace App\Models\Store;

use App\Access\Contracts\ScopedRecord;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Store\LinkStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M-31: a sales basket and its purchase link (SAL-01..05). The owner comes from the server-side record only; the
 * browser never names a member (SAL-05). The token is kept encrypted for its owner to copy again; lookups use the hash.
 *
 * @property int $id
 * @property string $number
 * @property string $token_hash
 * @property string $token
 * @property LinkStatus $status
 * @property int $owner_id
 * @property int|null $customer_id
 * @property list<array{variant_id: int, quantity: int}> $lines
 * @property string|null $note
 * @property CarbonInterface $expires_at
 * @property int|null $used_order_id
 * @property int|null $replaces_id
 * @property string|null $cancel_reason
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read User $owner
 * @property-read Customer|null $customer
 * @property-read Order|null $usedOrder
 */
class StoreLink extends Model implements ScopedRecord
{
    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash'];

    protected $attributes = ['lock_version' => 1];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function usedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'used_order_id');
    }

    public function isLapsed(?CarbonInterface $at = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    public function accessBranchIds(): array
    {
        // A link sells for the store's receiving branch (where its order will open).
        $branchId = StoreSettings::current()->branch_id;

        return $branchId === null ? [] : [$branchId];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return [$this->owner_id];
    }

    public function accessOwnerId(): ?int
    {
        return $this->owner_id;
    }

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'status' => LinkStatus::class,
            'lines' => 'array',
            'expires_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
