<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Audit\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One audit event (SHR-11). Append-only: the model refuses updates and deletes, and database triggers refuse
 * them too (ADR-0006). Scope facts (branches, department) are those of the subject when the event happened.
 *
 * @property int $id
 * @property string $action
 * @property string $area
 * @property int|null $actor_user_id
 * @property string|null $actor_name
 * @property string $source
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property int|null $department_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property Carbon $occurred_at
 */
class AuditEvent extends Model implements ScopedRecord
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @var list<int>|null
     */
    private ?array $branchIdsCache = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }

    public function actionEnum(): ?AuditAction
    {
        return AuditAction::tryFrom($this->action);
    }

    /**
     * @return list<int>
     */
    public function accessBranchIds(): array
    {
        return $this->branchIdsCache ??= array_values(array_map(intval(...), DB::table('audit_event_branches')
            ->where('audit_event_id', $this->id)->orderBy('branch_id')->pluck('branch_id')->all()));
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
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
