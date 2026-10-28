<?php

namespace App\Actions\StateMachines\M27ActionResult;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M07Task\T07CancelTask;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ActionStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\OrderAction;
use App\Models\Task;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\ActionLoader;
use App\Workflow\StageEngine;
use App\Workflow\Transition;
use App\Workflow\WorkflowLock;
use App\Workflow\WorkGuard;

/**
 * M-27-T08 (P-28): a ready action proven not to apply (its need condition is now false) becomes «غير مطلوب». Only when
 * no work started and no result or reference exists; its unstarted task is cancelled. Anything else is a change or a
 * specific allowance, never a silent drop.
 */
final class T08MarkNotRequired
{
    public function __construct(private readonly StageEngine $engine, private readonly T07CancelTask $cancel) {}

    public function handle(User $actor, OrderAction $action, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): OrderAction
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $action, $reason, $lockVersion, $source) {
            [$order, $action] = ActionLoader::lock($action, $lockVersion);
            Access::authorize($actor, Permission::P28, $order);
            Transition::check(StateMachine::M27, ActionStatus::transitions(), $action->status, 'T08');

            if ($action->appliesTo($order)) {
                Transition::refuse(StateMachine::M27, 'T08', 'need_applies');
            }
            if ($action->source_reference !== null) {
                Transition::refuse(StateMachine::M27, 'T08', 'has_reference');
            }
            $tasks = Task::query()->where('order_action_id', $action->id)->get();
            if ($tasks->contains(fn (Task $t) => ! in_array($t->status, [TaskStatus::Unassigned, TaskStatus::Assigned, TaskStatus::Cancelled], true))) {
                Transition::refuse(StateMachine::M27, 'T08', 'work_started');
            }

            $this->engine->setStatus($action, ActionStatus::Ready, ActionStatus::NotRequired, 'T08', $actor, $reason, source: $source);
            foreach ($tasks->filter(fn (Task $t) => $t->status->isOpen()) as $task) {
                $task->setRelation('order', $order);
                $this->cancel->cancel($actor, $task, $reason, $source);
            }
            Audit::record(AuditAction::ActionNeedChanged, $order, $actor, before: ['action' => $action->key, 'status' => ActionStatus::Ready->value],
                after: ['action' => $action->key, 'status' => ActionStatus::NotRequired->value], reason: $reason, source: $source, label: $order->number);
            WorkflowLock::touch($order);
            $this->engine->refresh($order, $actor);

            return $action;
        });
    }
}
