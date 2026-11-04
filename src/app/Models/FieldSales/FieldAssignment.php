<?php

namespace App\Models\FieldSales;

use App\Access\Contracts\ScopedRecord;
use App\Models\AppointmentRequest;
use App\Models\Branch;
use App\Models\Interest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead or an appointment request handed to a field sales rep (ADR-0041): the rep's task, with its response time.
 * One open row per subject; re-assigning ends the open one as `reassigned`, and the subject's own end (converted,
 * closed, booked) ends it as `done`.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $branch_id
 * @property int $rep_id
 * @property int|null $assigned_by
 * @property CarbonInterface $assigned_at
 * @property CarbonInterface|null $due_at
 * @property string $status
 * @property string|null $outcome
 * @property CarbonInterface|null $ended_at
 * @property string|null $note
 * @property-read User $rep
 * @property-read User|null $assigner
 * @property-read Branch $branch
 */
class FieldAssignment extends Model implements ScopedRecord
{
    public const INTEREST = 'interest';

    public const REQUEST = 'appointment_request';

    public const OPEN = 'open';

    public const DONE = 'done';

    public const REASSIGNED = 'reassigned';

    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Interest, $this> */
    public function interest(): BelongsTo
    {
        return $this->belongsTo(Interest::class, 'subject_id');
    }

    /** @return BelongsTo<AppointmentRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'subject_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isOverdue(?CarbonInterface $now = null): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->lte($now ?? now());
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
        return [$this->rep_id];
    }

    public function accessOwnerId(): ?int
    {
        return $this->assigned_by;
    }

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'due_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
