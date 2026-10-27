<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\MembershipStatus;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A user's staff membership in the facility (M-22). Status changes only through the M-22 actions.
 *
 * @property int $id
 * @property int $user_id
 * @property MembershipStatus $status
 * @property string|null $job_title
 * @property int|null $department_id
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason
 * @property-read User $user
 * @property-read Department|null $department
 * @property-read Collection<int, Branch> $branches
 * @property-read Collection<int, MembershipRole> $roles
 * @property-read Collection<int, MemberGrant> $grants
 */
#[Fillable(['user_id', 'job_title', 'department_id'])]
class Membership extends Model implements ScopedRecord
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /**
     * @return HasMany<MembershipRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(MembershipRole::class);
    }

    /**
     * @return HasMany<MemberGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(MemberGrant::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function isOwner(): bool
    {
        return Facility::current()?->owner_membership_id === $this->id;
    }

    /**
     * @return list<int>
     */
    public function branchIds(): array
    {
        return array_values(array_map(intval(...), $this->branches()->pluck('branches.id')->all()));
    }

    public function accessBranchIds(): array
    {
        return $this->branchIds();
    }

    public function accessDepartmentId(): ?int
    {
        return $this->department_id;
    }

    public function accessAssigneeIds(): array
    {
        return [];
    }

    public function accessOwnerId(): ?int
    {
        return $this->user_id;
    }

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'disabled_at' => 'datetime',
        ];
    }
}
