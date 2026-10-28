<?php

namespace App\Tasks;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\TaskStatus;
use App\Files\AttachmentService;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderMeasurement;
use App\Models\Task;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Workflow\Catalogue\CompletionMode;
use App\Workflow\TaskEligibility;
use App\Workflow\TaskQueries;

/**
 * Shapes tasks for the P5 task screens. The executor sees the task's own fields only (WF-08): no order money and no
 * customer file — the customer name appears in the list row and the visit block, nothing else. `can` is advisory UI
 * state; every write re-authorizes on the task.
 */
final class TaskPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Task $task, User $actor): array
    {
        // A relation loaded before an assignment changed its key would show the old holder.
        foreach (['department' => $task->department_id, 'assignee' => $task->assignee_id] as $relation => $key) {
            if ($task->relationLoaded($relation) && $task->getRelation($relation)?->getKey() !== $key) {
                $task->unsetRelation($relation);
            }
        }
        $task->loadMissing(TaskQueries::ROW_RELATIONS);
        $department = $task->department;
        $assignee = $task->assignee;

        return [
            'id' => $task->id,
            'title' => $task->title->get(),
            'status' => $task->status->value,
            'kind' => $task->kind,
            'order' => [
                'id' => $task->order_id,
                'number' => $task->order->number,
                'customer' => $task->order->customer->name,
                'viewable' => Access::can($actor, Permission::P16, $task->order),
            ],
            'stage' => $task->stage->name->get(),
            'department' => $department === null ? null : ['id' => $department->id, 'name' => OrgPresenter::name($department)],
            'assignee' => $assignee === null ? null : ['id' => $assignee->id, 'name' => $assignee->name],
            'mine' => $task->assignee_id === $actor->id,
            'due_at' => $task->due_at?->toIso8601String(),
            'overdue' => $task->due_at !== null && $task->status->isOpen() && $task->due_at->isPast(),
            'waiting_need' => $task->waiting_need,
            'waiting_follow_up_on' => $task->waiting_follow_up_on?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'lock_version' => $task->lock_version,
            'can' => self::can($task, $actor),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function can(Task $task, User $actor): array
    {
        $open = $task->status->isOpen();
        $holder = $task->assignee_id === $actor->id && Access::can($actor, Permission::P24, $task);
        $unassigned = $task->status === TaskStatus::Unassigned;

        return [
            'claim' => $unassigned && $task->department_id !== null && TaskEligibility::allows($actor->id, $task),
            'assign' => $unassigned && Access::can($actor, Permission::P22, $task),
            'reassign' => $open && ! $unassigned && Access::can($actor, Permission::P23, $task),
            'start' => $holder && $task->status === TaskStatus::Assigned,
            'wait' => $holder && in_array($task->status, [TaskStatus::Assigned, TaskStatus::InProgress], true),
            'resolve' => $holder && $task->status === TaskStatus::Waiting,
            'complete' => $holder && in_array($task->status, [TaskStatus::Assigned, TaskStatus::InProgress], true),
            'cancel' => $open && (Access::can($actor, Permission::P22, $task) || Access::can($actor, Permission::P28, $task->order)),
            'measure' => $holder && $open && $task->action?->type->completion() === CompletionMode::Measurement,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Task $task, User $actor, AttachmentService $files): array
    {
        $task->loadMissing(['order.customer', 'stage', 'action']);
        $order = $task->order;
        $action = $task->action;
        $inputs = $action === null ? [] : ($action->inputs ?? []);
        $follow = $order->assignee_id === null ? null : User::query()->find($order->assignee_id);

        $appointment = Appointment::query()->with('members:id,name')->where('task_id', $task->id)
            ->whereNotIn('status', ['cancelled'])->orderByDesc('id')->first();
        $visit = $appointment === null ? null : self::visit($appointment, $actor);

        return self::row($task, $actor) + [
            'action' => $action === null ? null : [
                'type' => $action->type->value,
                'name' => $action->name->get(),
                'completion' => $action->type->completion()->value,
                'requires_attachment' => ($inputs['requires_attachment'] ?? false) === true,
                'measurement_kind' => $inputs['measurement_kind'] ?? 'final',
            ],
            'result_note' => $task->result_note,
            'lines' => self::lines($order),
            'attachments' => $task->attachments()->orderBy('id')->get()->map(fn ($a) => [
                'uuid' => $a->uuid, 'name' => $a->original_name, 'size_bytes' => $a->size_bytes,
                'url' => $files->downloadUrl($a, $actor),
            ])->all(),
            'can_attach' => $task->status->isOpen() && Access::can($actor, Permission::P24, $task),
            'measurements' => self::measurements($order),
            'visit' => $visit,
            'follow_up' => $follow === null ? null : $follow->name,
        ];
    }

    /**
     * @return list<array{id: int, name: string, quantity: string, unit: string|null}>
     */
    private static function lines(Order $order): array
    {
        return array_values(OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->orderBy('position')->get()
            ->map(function (OrderLine $line) {
                $item = $line->snapshot['item'] ?? [];
                $pick = static fn (mixed $v): string => is_array($v) ? (string) ($v[app()->getLocale()] ?? $v['ar'] ?? $v['en'] ?? '') : '';
                $name = $line->description ?? '';
                if ($line->line_type->value !== 'custom_need') {
                    $name = $pick($item['name'] ?? null);
                    if ($line->line_type->value === 'ready_variant') {
                        $name = $pick($item['product_name'] ?? null).' — '.$name;
                    }
                }

                return ['id' => $line->id, 'name' => $name, 'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'), 'unit' => $line->unit];
            })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function measurements(Order $order): array
    {
        return array_values(OrderMeasurement::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn (OrderMeasurement $m) => [
            'id' => $m->id,
            'order_line_id' => $m->order_line_id,
            'location' => $m->location,
            'name' => $m->name,
            'value' => rtrim(rtrim((string) $m->value, '0'), '.'),
            'unit' => $m->unit,
            'kind' => $m->kind,
            'measured_on' => $m->measured_on->toDateString(),
            'note' => $m->note,
        ])->all());
    }

    /**
     * The visit block (appointment of this task): shown to the members booked on it or to whoever holds P-54.
     *
     * @return array<string, mixed>|null
     */
    private static function visit(Appointment $appointment, User $actor): ?array
    {
        $booked = in_array($actor->id, $appointment->memberIds(), true);
        if (! $booked && ! Access::can($actor, Permission::P54, $appointment)) {
            return null;
        }

        return [
            'id' => $appointment->id,
            'kind' => $appointment->kind,
            'status' => $appointment->status->value,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'address' => $appointment->address,
            'maps_url' => $appointment->mapsUrl(),
            'customer' => $appointment->order->customer->name,
            'members' => $appointment->members->pluck('name')->all(),
        ];
    }
}
