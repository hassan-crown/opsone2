<?php

namespace App\Actions\StateMachines\M27ActionResult;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ActionStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\StageStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use App\Models\Task;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Support\LockingTransaction;
use App\Workflow\ActionLoader;
use App\Workflow\Catalogue\CompletionMode;
use App\Workflow\StageEngine;
use App\Workflow\TaskEligibility;
use App\Workflow\TaskFactory;
use App\Workflow\TaskLog;
use App\Workflow\Transition;
use App\Workflow\WorkflowLock;
use App\Workflow\WorkGuard;

/**
 * Rework of a done action (P-27; WF-08, WF-11, P01 «تصحيح العمل»), one decision applying, in one transaction:
 *
 * - M-27-T05 → «يحتاج إعادة عمل», then M-27-T06 → ready with known work: the same task reopened (M-07-T08) while its
 *   stage is the active one and its holder is still eligible, otherwise a linked correction task (M-07-T00). A
 *   source-verified action waits for a new result; an old result never completes the new content.
 * - Later work depending on it and not yet done is held (cannot complete) until the rework is verified; nothing else
 *   reopens, and no collection or stock movement is repeated.
 * - A completed stage becomes «مكتملة مع تصحيح مطلوب» (M-06-T05) without becoming a second active main stage.
 * - A finished or closed order returns to «جارٍ» (M-04-T08, closing correction), keeping the earlier closure.
 */
final class T05ReworkAction
{
    public function __construct(
        private readonly StageEngine $engine,
        private readonly TaskFactory $tasks,
        private readonly TaskLog $log,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, OrderAction $action, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): OrderAction
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $action, $reason, $lockVersion, $source) {
            [$order, $action] = ActionLoader::lock($action, $lockVersion);
            Access::authorize($actor, Permission::P27, $action);
            $from = $action->status;
            Transition::check(StateMachine::M27, ActionStatus::transitions(), $from, 'T05');
            if ($order->status === CustomOrderStatus::Paused->value) {
                Transition::refuse(StateMachine::M27, 'T05', 'order_paused');
            }
            if (! in_array($order->status, [CustomOrderStatus::InProgress->value, CustomOrderStatus::ExecutionFinished->value, CustomOrderStatus::Closed->value], true)) {
                Transition::refuse(StateMachine::M27, 'T05', 'order_not_running');
            }

            $stage = OrderStage::query()->findOrFail($action->order_stage_id);
            $stage->setRelation('order', $order);
            $activeStageId = OrderWorkflow::query()->where('order_id', $order->id)->value('active_stage_id');

            // M-04-T08: a closing correction reopens the order's execution, keeping the earlier finish/closure.
            if ($order->status !== CustomOrderStatus::InProgress->value) {
                $this->reopenOrder($actor, $order, $reason, $source);
            }

            $previousSource = $action->source_reference;
            $action->forceFill(['rework_count' => $action->rework_count + 1, 'allowed_by' => null, 'allowed_reason' => null])->save();
            $this->engine->setStatus($action, $from, ActionStatus::NeedsRework, 'T05', $actor, $reason, ['previous_source' => $previousSource], $source);

            $held = $this->holdDependents($order, $action);

            if ($stage->status === StageStatus::Completed) {
                $stage->forceFill(['status' => StageStatus::CompletedNeedsCorrection])->save();
                $this->transitions->record(StateMachine::M06, 'T05', $stage, StageStatus::Completed, StageStatus::CompletedNeedsCorrection, $actor, $source, $reason, [
                    'order_id' => $order->id, 'action' => $action->key,
                ]);
            }

            // M-27-T06: available again with known work.
            $task = null;
            if ($action->type->completion() === CompletionMode::Source) {
                $action->forceFill(['source_reference' => null])->save();
            } else {
                $task = $this->reworkTask($actor, $order, $action, $stage->id === $activeStageId, $reason, $source);
            }
            $this->engine->setStatus($action, ActionStatus::NeedsRework, ActionStatus::Ready, 'T06', $actor, context: ['task_id' => $task?->id]);

            Audit::record(AuditAction::ActionReworked, $order, $actor, before: ['action' => $action->key, 'status' => $from->value],
                after: ['action' => $action->key, 'held' => $held, 'task_id' => $task?->id], reason: $reason, source: $source, label: $order->number);
            WorkflowLock::touch($order);

            return $action->refresh();
        });
    }

    private function reopenOrder(User $actor, Order $order, string $reason, string $source): void
    {
        $previous = $order->status;
        OrderTransitions::check($order, 'T08');
        $order->status = CustomOrderStatus::InProgress->value;
        $order->lock_version++;
        $order->save();
        $this->transitions->record($order->kind->machine(), 'T08', $order, $previous, CustomOrderStatus::InProgress, $actor, $source, $reason);
        Audit::record(AuditAction::OrderReopenedForCorrection, $order, $actor, before: ['status' => $previous],
            after: ['status' => CustomOrderStatus::InProgress->value], reason: $reason, source: $source, label: $order->number);
    }

    /**
     * @return list<string> keys of the held actions
     */
    private function holdDependents(Order $order, OrderAction $action): array
    {
        $held = [];
        foreach (OrderAction::query()->where('order_id', $order->id)->whereKeyNot($action->id)->get() as $other) {
            if (in_array($action->key, $other->depends_on ?? [], true)
                && in_array($other->status, [ActionStatus::Unavailable, ActionStatus::Ready, ActionStatus::AwaitingResult], true)) {
                $other->forceFill(['held_by_action_id' => $action->id])->save();
                $held[] = $other->key;
            }
        }

        return $held;
    }

    private function reworkTask(User $actor, Order $order, OrderAction $action, bool $stageActive, string $reason, string $source): Task
    {
        $last = Task::query()->where('order_action_id', $action->id)->where('status', TaskStatus::Completed->value)->orderByDesc('id')->first();

        if ($stageActive && $last !== null && $last->assignee_id !== null) {
            $last->setRelation('order', $order);
            if (TaskEligibility::allows($last->assignee_id, $last)) {
                Transition::check(StateMachine::M07, TaskStatus::transitions(), $last->status, 'T08');
                $last->forceFill(['status' => TaskStatus::Assigned, 'completed_at' => null, 'completed_by' => null]);
                $this->log->record($last, 'T08', TaskStatus::Completed, TaskStatus::Assigned, $actor, $source, $reason);
                $this->tasks->announce($last, $order);

                return $last;
            }
        }

        return $this->tasks->forAction($order, $action, $actor, $source, Task::KIND_CORRECTION, $last, $reason);
    }
}
