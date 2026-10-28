<?php

namespace App\Workflow;

use App\Access\TransitionRecorder;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Notifier;

/**
 * M-07-T00: creates an action's work once, when the action becomes available (WF-06 «إنشاء مهامها دون تكرار»), routed
 * as the flow says: the order's responsible person or a named member when eligible, otherwise unassigned under a
 * known follower; a department routing puts it in that department's inbox.
 */
final class TaskFactory
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly Notifier $notifier,
    ) {}

    public function forAction(Order $order, OrderAction $action, ?User $actor, string $source, string $kind = Task::KIND_ACTION, ?Task $corrects = null, ?string $reason = null): Task
    {
        if ($kind === Task::KIND_ACTION && $action->tasks()->where('kind', Task::KIND_ACTION)->exists()) {
            return $action->tasks()->where('kind', Task::KIND_ACTION)->firstOrFail();
        }

        $task = new Task([
            'order_id' => $order->id,
            'order_stage_id' => $action->order_stage_id,
            'order_action_id' => $action->id,
            'kind' => $kind,
            'corrects_task_id' => $corrects?->id,
            'title' => $action->name,
            'department_id' => $action->assign_type === 'department' ? $action->assign_id : null,
            'follow_up_user_id' => TaskEligibility::followerOf($order),
            'status' => TaskStatus::Unassigned,
        ]);
        $task->setRelation('order', $order);

        $target = match ($action->assign_type) {
            'order_assignee' => $order->assignee_id,
            'member' => $action->assign_id,
            default => null,
        };
        if ($kind === Task::KIND_CORRECTION && $corrects?->assignee_id !== null) {
            $target = $corrects->assignee_id;
        }
        if ($target !== null && TaskEligibility::allows((int) $target, $task)) {
            $task->assignee_id = (int) $target;
            $task->status = TaskStatus::Assigned;
            $task->assigned_at = now();
        }
        $task->save();

        $this->transitions->record(StateMachine::M07, 'T00', $task, null, $task->status, $actor, $source, $reason, [
            'order_id' => $order->id, 'action' => $action->key, 'kind' => $kind, 'routing' => $action->assign_type,
            'corrects_task_id' => $corrects?->id,
        ]);
        $this->announce($task, $order);

        return $task;
    }

    /**
     * Assignment and unassigned-work notifications (through the outbox, inside the transaction).
     */
    public function announce(Task $task, Order $order): void
    {
        $params = ['order' => $order->number, 'task' => (string) $task->title->get(app()->getLocale())];

        if ($task->assignee_id !== null) {
            $assignee = User::query()->find($task->assignee_id);
            if ($assignee !== null) {
                $this->notifier->send($assignee, 'task.assigned', $params, $task, "task:{$task->id}:assigned:{$assignee->id}:{$task->lock_version}");
            }

            return;
        }

        $follower = $task->follow_up_user_id === null ? null : User::query()->find($task->follow_up_user_id);
        if ($follower !== null) {
            $this->notifier->send($follower, 'task.unassigned', $params, $task, "task:{$task->id}:unassigned:{$task->lock_version}");
        }
    }
}
