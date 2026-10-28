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
use App\Workflow\TaskLog;
use App\Workflow\Transition;

/**
 * M-07-T10 (P-24): an eligible active member takes unassigned work from their authorized department inbox. The order
 * lock makes the check and the update atomic with any concurrent claim or assignment: the later attempt is refused
 * (`not_from_assigned`) and sees who holds it. Taking work never widens access to the rest of the order.
 */
final class T10ClaimTask
{
    public function __construct(private readonly TaskLog $log) {}

    public function handle(User $actor, Task $task, string $source = TransitionRecorder::SOURCE_WEB): Task
    {
        return LockingTransaction::run(function () use ($actor, $task, $source) {
            [, $task] = $this->log->lock($task, null);
            Access::authorize($actor, Permission::P24, $task);
            Transition::check(StateMachine::M07, TaskStatus::transitions(), $task->status, 'T10');

            if ($task->department_id === null) {
                Transition::refuse(StateMachine::M07, 'T10', 'not_in_inbox');
            }
            if (! TaskEligibility::allows($actor->id, $task)) {
                Transition::refuse(StateMachine::M07, 'T10', 'not_eligible');
            }

            $task->forceFill(['assignee_id' => $actor->id, 'status' => TaskStatus::Assigned, 'assigned_at' => now(), 'claimed_at' => now()]);
            $this->log->record($task, 'T10', TaskStatus::Unassigned, TaskStatus::Assigned, $actor, $source,
                context: ['claimed_by' => $actor->id], audit: AuditAction::TaskClaimed, after: ['assignee_id' => $actor->id]);

            return $task;
        });
    }
}
