<?php

namespace App\Actions\StateMachines\M07Task;

use App\Access\TransitionRecorder;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\Task;
use App\Models\User;
use App\Workflow\TaskEligibility;
use App\Workflow\TaskFactory;
use App\Workflow\TaskLog;
use App\Workflow\Transition;

/**
 * M-07-T09 (P-06 via M-22-T01 and the follow-up service): a disabled member's open work returns to «غير مسندة» in its
 * department inbox or under the order's follower, with its history kept; follow-ups they held move to the order's
 * active follower (or the owner). Runs inside the disabling transaction, after the membership row is locked.
 */
final class T09ReleaseDisabledMemberTasks
{
    public function __construct(private readonly TaskLog $log, private readonly TaskFactory $tasks) {}

    /**
     * @return int number of released tasks
     */
    public function handle(User $actor, User $disabled, string $reason): int
    {
        $released = 0;
        $ids = Task::query()->where('assignee_id', $disabled->id)
            ->whereIn('status', [TaskStatus::Assigned->value, TaskStatus::InProgress->value, TaskStatus::Waiting->value])
            ->orderBy('order_id')->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $task = Task::query()->findOrFail((int) $id);
            $order = Order::query()->lockForUpdate()->findOrFail($task->order_id);
            $task = Task::query()->lockForUpdate()->findOrFail((int) $id);
            $task->setRelation('order', $order);
            $from = $task->status;
            Transition::check(StateMachine::M07, TaskStatus::transitions(), $from, 'T09');

            $task->forceFill([
                'status' => TaskStatus::Unassigned,
                'assignee_id' => null,
                'follow_up_user_id' => $task->follow_up_user_id === $disabled->id ? TaskEligibility::followerOf($order) : $task->follow_up_user_id,
            ]);
            $this->log->record($task, 'T09', $from, TaskStatus::Unassigned, $actor, TransitionRecorder::SOURCE_SYSTEM, $reason,
                ['previous_assignee_id' => $disabled->id], AuditAction::TaskReleased, ['assignee_id' => $disabled->id], ['assignee_id' => null]);
            $this->tasks->announce($task, $order);
            $released++;
        }

        // Unassigned work and stages they followed get a known follower again.
        Task::query()->with('order')->where('follow_up_user_id', $disabled->id)->whereIn('status', TaskStatus::open())->get()
            ->each(fn (Task $t) => $t->forceFill(['follow_up_user_id' => TaskEligibility::followerOf($t->order)])->save());
        OrderStage::query()->with('order')->where('follow_up_user_id', $disabled->id)->whereIn('status', ['not_started', 'in_progress', 'waiting'])->get()
            ->each(fn (OrderStage $s) => $s->forceFill(['follow_up_user_id' => TaskEligibility::followerOf($s->order)])->save());

        return $released;
    }
}
