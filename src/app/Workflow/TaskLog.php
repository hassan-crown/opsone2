<?php

namespace App\Workflow;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Support\StaleRecord;

/**
 * Loading and logging for M-07 Actions: the order is locked first, then the task is re-read so a concurrent claim or
 * assignment is seen (the later attempt gets the current result, never a silent overwrite).
 */
final class TaskLog
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    /**
     * @return array{0: Order, 1: Task}
     */
    public function lock(Task $task, ?int $expectedVersion): array
    {
        $order = WorkflowLock::order($task->order_id);
        $fresh = Task::query()->lockForUpdate()->findOrFail($task->id);
        $fresh->setRelation('order', $order);

        if ($expectedVersion !== null && $fresh->lock_version !== $expectedVersion) {
            throw new StaleRecord($fresh->lock_version);
        }

        return [$order, $fresh];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        Task $task,
        string $code,
        TaskStatus $from,
        TaskStatus $to,
        ?User $actor,
        string $source,
        ?string $reason = null,
        array $context = [],
        ?AuditAction $audit = null,
        array $before = [],
        array $after = [],
    ): void {
        $task->lock_version++;
        $task->save();

        $this->transitions->record(StateMachine::M07, $code, $task, $from, $to, $actor, $source, $reason, ['order_id' => $task->order_id, ...$context]);
        if ($audit !== null) {
            Audit::record($audit, $task, $actor, before: $before, after: $after, reason: $reason, source: $source,
                branchIds: [$task->order->branch_id], departmentId: $task->department_id, label: $task->order->number);
        }
    }
}
