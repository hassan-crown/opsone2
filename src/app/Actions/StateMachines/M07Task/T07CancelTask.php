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
use App\Workflow\TaskLog;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * M-07-T07 (P-22 or P-28): stop work with a reason, keeping what was done. Work whose result the stage still needs
 * cannot be cancelled here: the stage condition is handled first (allowance, not-required or edit of upcoming work).
 */
final class T07CancelTask
{
    public function __construct(private readonly TaskLog $log) {}

    public function handle(User $actor, Task $task, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Task
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $task, $reason, $lockVersion, $source) {
            [, $task] = $this->log->lock($task, $lockVersion);
            if (! Access::can($actor, Permission::P22, $task) && ! Access::can($actor, Permission::P28, $task->order)) {
                throw new AuthorizationException(__('access.denied.no_grant'));
            }

            return $this->cancel($actor, $task, $reason, $source);
        });
    }

    /**
     * Shared with the engine (not-required, edits); the caller holds the order lock and checked the permission.
     */
    public function cancel(?User $actor, Task $task, string $reason, string $source): Task
    {
        $from = $task->status;
        Transition::check(StateMachine::M07, TaskStatus::transitions(), $from, 'T07');

        $action = $task->action;
        if ($action !== null && $action->isRequired() && ! $action->status->satisfies()
            && $action->tasks()->whereIn('status', TaskStatus::open())->whereKeyNot($task->id)->doesntExist()) {
            Transition::refuse(StateMachine::M07, 'T07', 'required_result');
        }

        $task->forceFill(['status' => TaskStatus::Cancelled, 'cancel_reason' => $reason]);
        $this->log->record($task, 'T07', $from, TaskStatus::Cancelled, $actor, $source, $reason,
            audit: AuditAction::TaskCancelled, before: ['status' => $from->value], after: ['status' => TaskStatus::Cancelled->value]);

        return $task;
    }
}
