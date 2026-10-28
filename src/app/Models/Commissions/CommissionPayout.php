<?php

namespace App\Models\Commissions;

use App\Access\Permission;
use App\Enums\PayoutStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * M-33 «صرف العمولة» of one member for one period (SAL-14). Its scope is the member's: `own` is the member, branch
 * scopes need every branch of the member's membership — a branch finance officer pays the members of their branches.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonInterface $period_from
 * @property CarbonInterface $period_to
 * @property PayoutStatus $status
 * @property int|null $amount_minor
 * @property string|null $method
 * @property CarbonInterface|null $paid_on
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $corrected_minor
 * @property string|null $reason
 * @property int|null $created_by
 * @property int|null $reserved_by
 * @property CarbonInterface|null $reserved_at
 * @property int|null $paid_by
 * @property CarbonInterface|null $paid_at
 * @property int|null $closed_by
 * @property CarbonInterface|null $closed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read User $member
 */
class CommissionPayout extends Model implements AttachmentOwner
{
    public const METHODS = ['transfer', 'cash', 'cheque', 'payroll'];

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Payouts are never deleted.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<CommissionPayoutLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CommissionPayoutLine::class, 'payout_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** What the payout takes from the member's balance: the corrected amount once M-33-T04 recorded it. */
    public function effectiveMinor(): int
    {
        return $this->corrected_minor ?? (int) $this->amount_minor;
    }

    public function accessBranchIds(): array
    {
        return Membership::query()->where('user_id', $this->user_id)->first()?->branchIds() ?? [];
    }

    public function accessDepartmentId(): ?int
    {
        $department = Membership::query()->where('user_id', $this->user_id)->value('department_id');

        return $department === null ? null : (int) $department;
    }

    public function accessAssigneeIds(): array
    {
        return [];
    }

    public function accessOwnerId(): ?int
    {
        return $this->user_id;
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P94;
    }

    /**
     * A paid payout only receives the proof of its correction (M-33-T04), which is P-97's; every other proof is P-96's.
     */
    public function attachmentUploadPermission(): Permission
    {
        return $this->status === PayoutStatus::Paid ? Permission::P97 : Permission::P96;
    }

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'status' => PayoutStatus::class,
            'amount_minor' => 'integer',
            'paid_on' => 'date',
            'corrected_minor' => 'integer',
            'reserved_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
