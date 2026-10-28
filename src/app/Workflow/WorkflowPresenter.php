<?php

namespace App\Workflow;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\ActionStatus;
use App\Enums\CustomOrderStatus;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use App\Models\Task;
use App\Models\User;

/**
 * Read model of an order's flow for the `OrderStages` screens (WF-06, WF-12): every stage with its actions and work,
 * the current stage, what blocks it and who is responsible, plus what the viewer may do. No money is exposed here;
 * the caller has already checked P-16 on the order.
 */
final class WorkflowPresenter
{
    /**
     * @return array<string, mixed>|null null for an order without a workflow (ready orders, pre-P5 orders)
     */
    public static function order(Order $order, User $viewer): ?array
    {
        $workflow = OrderWorkflow::query()->with('flowVersion')->where('order_id', $order->id)->first();
        if ($workflow === null) {
            return null;
        }

        $stages = OrderStage::query()->where('order_id', $order->id)->orderBy('position')->get();
        $actions = OrderAction::query()->where('order_id', $order->id)->orderBy('position')->get()->groupBy('order_stage_id');
        $tasks = Task::query()->where('order_id', $order->id)->orderBy('id')->get()->groupBy('order_action_id');
        $locale = app()->getLocale();

        $data = [
            'flow_version' => $workflow->flowVersion->number,
            'paused' => $order->status === CustomOrderStatus::Paused->value,
            'pause' => $workflow->pause_reason === null ? null : [
                'reason' => $workflow->pause_reason,
                'follow_up_user_id' => $workflow->pause_follow_up_id,
                'review_on' => $workflow->pause_review_on?->toDateString(),
            ],
            'active_stage_id' => $workflow->active_stage_id,
            'lock_version' => $order->lock_version,
            'can' => [
                'pause' => Access::can($viewer, Permission::P26, $order),
                'edit_upcoming' => Access::can($viewer, Permission::P28, $order),
                'finish_execution' => Access::can($viewer, Permission::P25, $order),
            ],
            'stages' => [],
            'blockers' => [],
        ];

        foreach ($stages as $stage) {
            $stage->setRelation('order', $order);
            $rows = [];
            foreach ($actions->get($stage->id, collect()) as $action) {
                $action->setRelation('order', $order);
                $rows[] = [
                    'id' => $action->id,
                    'key' => $action->key,
                    'type' => $action->type->value,
                    'group' => $action->type->group()->value,
                    'completion' => $action->type->completion()->value,
                    'name' => $action->name->get($locale),
                    'requirement' => $action->requirement,
                    'applies_when' => $action->applies_when,
                    'status' => $action->status->value,
                    'held' => ($action->held_by_action_id !== null || $action->held_by_change_id !== null),
                    'allowed_reason' => $action->allowed_reason,
                    'rework_count' => $action->rework_count,
                    'has_source' => $action->source_reference !== null,
                    'tasks' => $tasks->get($action->id, collect())->map(fn (Task $t) => [
                        'id' => $t->id,
                        'kind' => $t->kind,
                        'status' => $t->status->value,
                        'assignee_id' => $t->assignee_id,
                        'department_id' => $t->department_id,
                        'follow_up_user_id' => $t->follow_up_user_id,
                        'lock_version' => $t->lock_version,
                    ])->values()->all(),
                    'can' => [
                        'allow' => self::open($action) && $action->isRequired() && Access::can($viewer, Permission::P35, $action),
                        'rework' => in_array($action->status, [ActionStatus::Verified, ActionStatus::Allowed], true) && Access::can($viewer, Permission::P27, $action),
                    ],
                ];
                if ($stage->id === $workflow->active_stage_id && $action->isRequired() && (self::open($action) || ($action->held_by_action_id !== null || $action->held_by_change_id !== null))) {
                    $data['blockers'][] = [
                        'action' => $action->key,
                        'name' => $action->name->get($locale),
                        'type' => $action->type->value,
                        'status' => $action->status->value,
                        'held' => ($action->held_by_action_id !== null || $action->held_by_change_id !== null),
                        'responsible_user_id' => self::responsible($tasks->get($action->id)?->last(), $stage),
                    ];
                }
            }

            $data['stages'][] = [
                'id' => $stage->id,
                'key' => $stage->key,
                'position' => $stage->position,
                'name' => $stage->name->get($locale),
                'public_name' => $stage->public_name->get($locale),
                'show_to_customer' => $stage->show_to_customer,
                'status' => $stage->status->value,
                'follow_up' => ['type' => $stage->follow_up_type, 'user_id' => $stage->follow_up_user_id, 'department_id' => $stage->follow_up_department_id],
                'target_days' => $stage->target_days,
                'due_on' => $stage->started_at !== null && $stage->target_days !== null ? $stage->started_at->copy()->addDays($stage->target_days)->toDateString() : null,
                'started_at' => $stage->started_at?->toIso8601String(),
                'completed_at' => $stage->completed_at?->toIso8601String(),
                'can_complete' => $stage->id === $workflow->active_stage_id && Access::can($viewer, Permission::P25, $stage),
                'actions' => $rows,
            ];
        }

        return $data;
    }

    private static function responsible(?Task $task, OrderStage $stage): ?int
    {
        return $task->assignee_id ?? $stage->follow_up_user_id;
    }

    private static function open(OrderAction $action): bool
    {
        return in_array($action->status, [ActionStatus::Ready, ActionStatus::AwaitingResult, ActionStatus::NeedsRework, ActionStatus::Unavailable], true);
    }
}
