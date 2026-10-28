<?php

namespace App\Workflow;

use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;

/**
 * Reads an order's own stages back in the definition shape FlowCheck and the editor use.
 */
final class OrderDefinition
{
    /**
     * Stages at positions 1..$upTo (all when null).
     *
     * @return list<array<string, mixed>>
     */
    public static function fixedStages(Order $order, ?int $upTo = null): array
    {
        $stages = OrderStage::query()->where('order_id', $order->id)
            ->when($upTo !== null, fn ($q) => $q->where('position', '<=', $upTo))
            ->orderBy('position')->get();

        return array_values($stages->map(fn (OrderStage $stage) => self::stage($stage))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function stage(OrderStage $stage): array
    {
        return [
            'key' => $stage->key,
            'name' => $stage->name->toArray(),
            'public_name' => $stage->public_name->isEmpty() ? null : $stage->public_name->toArray(),
            'show_to_customer' => $stage->show_to_customer,
            'follow_up' => match ($stage->follow_up_type) {
                'member' => ['type' => 'member', 'id' => $stage->follow_up_user_id],
                'department' => ['type' => 'department', 'id' => $stage->follow_up_department_id],
                default => ['type' => 'order_assignee'],
            },
            'target_days' => $stage->target_days,
            'actions' => $stage->actions()->get()->map(fn (OrderAction $a) => [
                'key' => $a->key,
                'type' => $a->type->value,
                'name' => $a->name->toArray(),
                'requirement' => $a->requirement,
                'applies_when' => $a->applies_when,
                'assign' => $a->assign_id === null ? ['type' => $a->assign_type] : ['type' => $a->assign_type, 'id' => $a->assign_id],
                'inputs' => $a->inputs ?? [],
                'after' => null,
            ])->values()->all(),
        ];
    }
}
