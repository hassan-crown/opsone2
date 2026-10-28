<?php

namespace App\Actions\StateMachines\M07Task;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\OrganizationUnitStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\TaskEligibility;
use App\Workflow\TaskFactory;
use App\Workflow\TaskLog;
use App\Workflow\Transition;
use Illuminate\Validation\ValidationException;

/**
 * M-07-T01 (P-22): assign unassigned work to an eligible active member, or route it to a department's inbox (the task
 * stays «غير مسندة» there, no transition). Choosing a person never widens their access (WF-04).
 */
final class T01AssignTask
{
    public function __construct(private readonly TaskLog $log, private readonly TaskFactory $tasks) {}

    public function handle(User $actor, Task $task, ?int $assigneeId, ?int $departmentId = null, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Task
    {
        return LockingTransaction::run(function () use ($actor, $task, $assigneeId, $departmentId, $lockVersion, $source) {
            [$order, $task] = $this->log->lock($task, $lockVersion);
            Access::authorize($actor, Permission::P22, $task);
            Transition::check(StateMachine::M07, TaskStatus::transitions(), $task->status, 'T01');

            if ($departmentId !== null && $departmentId !== $task->department_id) {
                $active = Department::query()->whereKey($departmentId)->where('status', OrganizationUnitStatus::Active)->exists();
                $routed = $task->replicate();
                $routed->department_id = $departmentId;
                $routed->setRelation('order', $order);
                if (! $active || ! Access::can($actor, Permission::P22, $routed)) {
                    throw ValidationException::withMessages(['department_id' => __('workflow.validation.department_invalid')]);
                }
                $before = ['department_id' => $task->department_id];
                $task->department_id = $departmentId;
                $task->lock_version++;
                $task->save();
                Audit::record(AuditAction::TaskRouted, $task, $actor, before: $before, after: ['department_id' => $departmentId], source: $source,
                    branchIds: [$order->branch_id], departmentId: $departmentId, label: $order->number);
            }

            if ($assigneeId === null) {
                if ($departmentId !== null) {
                    $this->tasks->announce($task, $order);
                }

                return $task;
            }

            if (! TaskEligibility::allows($assigneeId, $task)) {
                throw ValidationException::withMessages(['assignee_id' => __('workflow.validation.assignee_ineligible')]);
            }

            $task->forceFill(['assignee_id' => $assigneeId, 'status' => TaskStatus::Assigned, 'assigned_at' => now()]);
            $this->log->record($task, 'T01', TaskStatus::Unassigned, TaskStatus::Assigned, $actor, $source,
                context: ['assignee_id' => $assigneeId], audit: AuditAction::TaskAssigned, after: ['assignee_id' => $assigneeId]);
            $this->tasks->announce($task, $order);

            return $task;
        });
    }
}
