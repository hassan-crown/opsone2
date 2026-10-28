<?php

namespace App\Flow;

use App\Customers\CustomerPresenter;
use App\Enums\CustomOrderStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\Department;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\OrderWorkflow;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Workflow\Catalogue\ActionGroup;
use App\Workflow\Catalogue\ActionType;
use App\Workflow\FlowProvisioner;
use App\Workflow\FlowTemplates;

/**
 * Read models for the flow settings screens (M-23: `FlowEdit`, `FlowStage1-5`, `FlowAction_*`, `WizardFlow`). The
 * published definition is read-only; a draft is the editable copy and is only checked when published.
 */
final class FlowPresenter
{
    /**
     * The facility flow as the editor needs it. `stages` is what the screen edits: the draft when one is open, else
     * the published definition.
     *
     * @return array<string, mixed>
     */
    public static function state(?Flow $flow = null): array
    {
        $flow ??= app(FlowProvisioner::class)->ensure();
        $flow->load('publishedVersion');
        /** @var FlowVersion $version */
        $version = $flow->publishedVersion;
        $draftBy = $flow->draft_by === null ? null : User::query()->whereKey($flow->draft_by)->value('name');

        return [
            'status' => $flow->status->value,
            'lock_version' => $flow->lock_version,
            'template_key' => $flow->template_key,
            'version' => ['number' => $version->number, 'published_at' => $version->published_at->toIso8601String()],
            'published' => $version->definition,
            'draft' => $flow->draft,
            'draft_by' => $draftBy === null ? null : (string) $draftBy,
            'draft_updated_at' => $flow->draft_updated_at?->toIso8601String(),
        ];
    }

    /**
     * Versions with how many running orders each still carries (WF-10: running orders keep their pinned version).
     *
     * @return list<array{number: int, published_at: string, running_orders: int}>
     */
    public static function versions(Flow $flow): array
    {
        $running = OrderWorkflow::query()
            ->join('orders', 'orders.id', '=', 'order_workflows.order_id')
            ->whereIn('orders.status', [CustomOrderStatus::InProgress->value, CustomOrderStatus::Paused->value])
            ->selectRaw('order_workflows.flow_version_id as version_id, count(*) as total')
            ->groupBy('order_workflows.flow_version_id')
            ->pluck('total', 'version_id');

        return array_values($flow->versions()->get()->map(fn (FlowVersion $v) => [
            'number' => $v->number,
            'published_at' => $v->published_at->toIso8601String(),
            'running_orders' => (int) ($running[$v->id] ?? 0),
        ])->all());
    }

    /**
     * Everything the stage/action editors choose from: the ten catalogue actions in five groups, the starting
     * templates and the people and departments an assignment or follow-up may name.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'catalogue' => ActionType::catalogue(),
            'groups' => array_map(fn (ActionGroup $g) => $g->value, ActionGroup::cases()),
            'templates' => array_map(fn (string $k) => ['key' => $k, 'label' => FlowTemplates::label($k), 'stages' => count(FlowTemplates::definition($k)['stages'])], FlowTemplates::keys()),
            'members' => CustomerPresenter::memberOptions(),
            'departments' => array_values(Department::query()->where('status', OrganizationUnitStatus::Active->value)->get()
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => OrgPresenter::name($d)])->all()),
        ];
    }
}
