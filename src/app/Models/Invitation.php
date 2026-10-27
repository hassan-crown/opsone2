<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Staff invitation (M-01). Valid for seven days; only the token hash is stored.
 *
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property string $token_hash
 * @property InvitationStatus $status
 * @property string|null $job_title
 * @property int|null $department_id
 * @property list<int>|null $branch_ids
 * @property list<array{role_template_id: int, scope?: string|null, targets?: list<int>|null}> $roles
 * @property int|null $invited_by_membership_id
 * @property int|null $replaced_by_id
 * @property int|null $membership_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $code_hash
 * @property Carbon|null $code_sent_at
 * @property Carbon|null $code_expires_at
 * @property int $code_attempts
 */
#[Fillable(['email', 'name', 'token_hash', 'job_title', 'department_id', 'branch_ids', 'roles', 'invited_by_membership_id', 'expires_at'])]
#[Hidden(['token_hash', 'code_hash'])]
class Invitation extends Model implements ScopedRecord
{
    public const VALID_DAYS = 7;

    protected $attributes = [
        'status' => 'pending',
        'code_attempts' => 0,
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * `s***@example.com`: enough to recognize an invitee in the audit log without storing the full address.
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public function auditLabel(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : self::maskEmail($this->email);
    }

    /**
     * @return array<string, mixed>
     */
    public function auditFacts(): array
    {
        return [
            'invitee' => self::maskEmail($this->email),
            'department_id' => $this->department_id,
            'branch_ids' => $this->accessBranchIds(),
            'roles' => $this->roles,
            'expires_at' => $this->expires_at,
        ];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'invited_by_membership_id');
    }

    public function isPastExpiry(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }

    /**
     * An invitation is placed in the organization (branches/department) it grants access to;
     * P-05 is checked against that placement.
     */
    public function accessBranchIds(): array
    {
        return array_map(intval(...), $this->branch_ids ?? []);
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
        return null;
    }

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'branch_ids' => 'array',
            'roles' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'code_sent_at' => 'datetime',
            'code_expires_at' => 'datetime',
        ];
    }
}
