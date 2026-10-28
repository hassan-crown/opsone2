<?php

namespace App\Actions\StateMachines\M07Task;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\ActionStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\TaskLog;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;

/**
 * M-07-T02 (P-24): start assigned work. Only an available action may start; stage dependencies are never skipped.
 */
final class T02StartTask
{
    public function __construct(private readonly TaskLog $log) {}

    public function handle(User $actor, Task $task, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Task
    {
        return LockingTransaction::run(function () use ($actor, $task, $lockVersion, $source) {
            [$order, $task] = $this->log->lock($task, $lockVersion);
            Access::authorize($actor, Permission::P24, $task);
            Transition::check(StateMachine::M07, TaskStatus::transitions(), $task->status, 'T02');
            WorkGuard::running($order, StateMachine::M07, 'T02');
            self::ensureActionOpen($task, 'T02');

            $task->status = TaskStatus::InProgress;
            $this->log->record($task, 'T02', TaskStatus::Assigned, TaskStatus::InProgress, $actor, $source);

            return $task;
        });
    }

    /**
     * The task's action must be available and not held by a rework of something it depends on (WF-11) or by a pending
     * agreement change (AGR-07).
     */
    public static function ensureActionOpen(Task $task, string $code): void
    {
        $action = $task->action;
        if ($action === null) {
            return;
        }
        if ($action->held_by_action_id !== null) {
            Transition::refuse(StateMachine::M07, $code, 'held_by_rework');
        }
        // A pending agreement change lists this work as affected: it waits for the decision (AGR-07).
        if ($action->held_by_change_id !== null) {
            Transition::refuse(StateMachine::M07, $code, 'held_by_agreement_change');
        }
        if (! in_array($action->status, [ActionStatus::Ready, ActionStatus::AwaitingResult], true)) {
            Transition::refuse(StateMachine::M07, $code, 'action_'.$action->status->value);
        }
    }
}
