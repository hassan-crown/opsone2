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
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * M-07-T03 (P-24): the work waits for a stated need with a follow-up date; work already done is kept.
 */
final class T03WaitForNeed
{
    public function __construct(private readonly TaskLog $log) {}

    public function handle(User $actor, Task $task, string $need, CarbonInterface $followUpOn, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Task
    {
        $need = WorkGuard::reason($need, 'need');
        if ($followUpOn->isPast() && ! $followUpOn->isToday()) {
            throw ValidationException::withMessages(['follow_up_on' => __('workflow.validation.follow_up_future')]);
        }

        return LockingTransaction::run(function () use ($actor, $task, $need, $followUpOn, $lockVersion, $source) {
            [, $task] = $this->log->lock($task, $lockVersion);
            Access::authorize($actor, Permission::P24, $task);
            $from = $task->status;
            Transition::check(StateMachine::M07, TaskStatus::transitions(), $from, 'T03');

            $task->forceFill(['status' => TaskStatus::Waiting, 'waiting_need' => $need, 'waiting_follow_up_on' => $followUpOn->toDateString()]);
            $this->log->record($task, 'T03', $from, TaskStatus::Waiting, $actor, $source, $need, ['follow_up_on' => $followUpOn->toDateString()]);

            return $task;
        });
    }
}
