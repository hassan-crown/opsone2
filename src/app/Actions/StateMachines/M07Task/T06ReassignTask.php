<?php

namespace App\Actions\StateMachines\M07Task;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\TaskEligibility;
use App\Workflow\TaskFactory;
use App\Workflow\TaskLog;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;
use Illuminate\Validation\ValidationException;

/**
 * M-07-T06 (P-23): hand open work to another eligible member with a reason; previous, new, reason and time are kept,
 * and nothing already done is redone.
 */
final class T06ReassignTask
{
    public function __construct(private readonly TaskLog $log, private readonly TaskFactory $tasks) {}

    public function handle(User $actor, Task $task, int $assigneeId, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Task
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $task, $assigneeId, $reason, $lockVersion, $source) {
            [$order, $task] = $this->log->lock($task, $lockVersion);
            Access::authorize($actor, Permission::P23, $task);
            Transition::check(StateMachine::M07, TaskStatus::transitions(), $task->status, 'T06');

            if ($assigneeId === $task->assignee_id || ! TaskEligibility::allows($assigneeId, $task)) {
                throw ValidationException::withMessages(['assignee_id' => __('workflow.validation.assignee_ineligible')]);
            }

            $previous = $task->assignee_id;
            $task->forceFill(['assignee_id' => $assigneeId, 'assigned_at' => now()]);
            $this->log->record($task, 'T06', $task->status, $task->status, $actor, $source, $reason,
                ['previous_assignee_id' => $previous, 'assignee_id' => $assigneeId],
                AuditAction::TaskReassigned, ['assignee_id' => $previous], ['assignee_id' => $assigneeId]);
            $this->tasks->announce($task, $order);

            return $task;
        });
    }
}
