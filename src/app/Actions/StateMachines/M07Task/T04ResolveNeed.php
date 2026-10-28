<?php

namespace App\Actions\StateMachines\M07Task;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\TaskLog;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;

/**
 * M-07-T04 (P-24): the awaited need is met; work continues.
 */
final class T04ResolveNeed
{
    public function __construct(private readonly TaskLog $log) {}

    public function handle(User $actor, Task $task, ?string $note = null, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Task
    {
        return LockingTransaction::run(function () use ($actor, $task, $note, $lockVersion, $source) {
            [$order, $task] = $this->log->lock($task, $lockVersion);
            Access::authorize($actor, Permission::P24, $task);
            Transition::check(StateMachine::M07, TaskStatus::transitions(), $task->status, 'T04');
            WorkGuard::running($order, StateMachine::M07, 'T04');

            $need = $task->waiting_need;
            $task->forceFill(['status' => TaskStatus::InProgress, 'waiting_need' => null, 'waiting_follow_up_on' => null]);
            $this->log->record($task, 'T04', TaskStatus::Waiting, TaskStatus::InProgress, $actor, $source, $note, ['need' => $need]);

            return $task;
        });
    }
}
