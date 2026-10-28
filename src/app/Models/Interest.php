<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\InterestChannel;
use App\Enums\InterestCloseReason;
use App\Enums\InterestStatus;
use Database\Factories\InterestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lead before an order (COM-03, M-03). State changes only through the M-03 actions.
 *
 * @property int $id
 * @property string|null $reference
 * @property InterestStatus $status
 * @property string $name
 * @property string|null $mobile
 * @property string|null $email
 * @property string $need
 * @property InterestChannel $channel
 * @property int $branch_id
 * @property int|null $customer_id
 * @property int|null $assignee_id
 * @property int|null $created_by
 * @property InterestCloseReason|null $close_reason
 * @property string|null $close_note
 * @property Carbon|null $closed_at
 * @property int|null $converted_customer_id
 * @property Carbon|null $converted_at
 * @property int|null $converted_by
 * @property-read Branch $branch
 * @property-read Customer|null $customer
 * @property-read Customer|null $convertedCustomer
 * @property-read User|null $assignee
 */
#[Fillable(['name', 'mobile', 'email', 'need', 'channel', 'branch_id', 'customer_id', 'assignee_id', 'created_by'])]
class Interest extends Model implements ScopedRecord
{
    /** @use HasFactory<InterestFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function isOpen(): bool
    {
        return $this->status === InterestStatus::New || $this->status === InterestStatus::Following;
    }

    public function accessBranchIds(): array
    {
        return [$this->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return $this->assignee_id === null ? [] : [(int) $this->assignee_id];
    }

    public function accessOwnerId(): ?int
    {
        return $this->created_by === null ? null : (int) $this->created_by;
    }

    protected function casts(): array
    {
        return [
            'status' => InterestStatus::class,
            'channel' => InterestChannel::class,
            'close_reason' => InterestCloseReason::class,
            'closed_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }
}
