<?php

namespace App\Actions\StateMachines\M07Task;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\AuditAction;
use App\Enums\ActionStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\OrderMeasurement;
use App\Models\Task;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\Catalogue\CompletionMode;
use App\Workflow\StageEngine;
use App\Workflow\TaskLog;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;
use Illuminate\Validation\ValidationException;

/**
 * M-07-T05 (P-24): finish the work with its result and required attachments. For task/measurement actions this is the
 * result (M-27-T03); it is never, by itself, a collection, delivery or approval (FUL-08).
 */
final class T05CompleteTask
{
    public function __construct(private readonly TaskLog $log, private readonly StageEngine $engine) {}

    public function handle(User $actor, Task $task, ?string $resultNote = null, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Task
    {
        return LockingTransaction::run(function () use ($actor, $task, $resultNote, $lockVersion, $source) {
            [$order, $task] = $this->log->lock($task, $lockVersion);
            Access::authorize($actor, Permission::P24, $task);
            $from = $task->status;
            Transition::check(StateMachine::M07, TaskStatus::transitions(), $from, 'T05');
            WorkGuard::running($order, StateMachine::M07, 'T05');
            T02StartTask::ensureActionOpen($task, 'T05');

            $action = $task->action;
            if ($action !== null) {
                $inputs = $action->inputs ?? [];
                if (($inputs['requires_attachment'] ?? false) === true && $task->attachments()->doesntExist()) {
                    throw ValidationException::withMessages(['attachments' => __('workflow.validation.attachment_required')]);
                }
                if ($action->type->completion() === CompletionMode::Measurement && ! self::hasMeasurements($task, $inputs['measurement_kind'] ?? 'final')) {
                    throw ValidationException::withMessages(['measurements' => __('workflow.validation.measurements_required')]);
                }
            }

            $task->forceFill([
                'status' => TaskStatus::Completed,
                'result_note' => $resultNote,
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ]);
            $this->log->record($task, 'T05', $from, TaskStatus::Completed, $actor, $source, context: ['action' => $action?->key],
                audit: AuditAction::TaskCompleted, after: ['status' => TaskStatus::Completed->value]);

            if ($action !== null && $action->type->completion()->createsTask()
                && in_array($action->status, [ActionStatus::Ready, ActionStatus::AwaitingResult], true)) {
                $this->engine->verify($order, $action, $actor, ['type' => 'task', 'id' => $task->id]);
            }

            return $task;
        });
    }

    /**
     * «تقديري» never satisfies «نهائي»; a final measurement satisfies either.
     */
    private static function hasMeasurements(Task $task, string $kind): bool
    {
        return OrderMeasurement::query()->where('order_id', $task->order_id)
            ->when($kind === 'final', fn ($q) => $q->where('kind', 'final'))
            ->exists();
    }
}
