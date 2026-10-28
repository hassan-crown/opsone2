<?php

namespace App\Workflow;

use App\Access\TransitionRecorder;
use App\Enums\ActionStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\StageStatus;
use App\Enums\StateMachine;
use App\Models\FlowVersion;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use App\Models\User;
use App\Workflow\Catalogue\CompletionMode;
use Illuminate\Support\Collection;

/**
 * The flow service (الـ «خدمة المسار» of M-06/M-27): pins a version on a new order, makes stages and actions available,
 * creates their work once, verifies results and keeps the stage's waiting marker. It never decides what only a person
 * may decide (completing a stage, allowing, reworking); those are the transition Actions. Callers hold the order lock.
 */
final class StageEngine
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly TaskFactory $tasks,
        private readonly FlowProvisioner $flows,
    ) {}

    /**
     * Pins the published version and starts the first stage (WF-01, WF-10). Inside the order-opening transaction.
     */
    public function start(Order $order, User $actor, string $source): OrderWorkflow
    {
        $flow = $this->flows->ensure();
        $version = FlowVersion::query()->findOrFail($flow->published_version_id);

        $workflow = OrderWorkflow::query()->create(['order_id' => $order->id, 'flow_version_id' => $version->id]);
        $this->instantiate($order, $version->definition['stages'], 0);

        $first = OrderStage::query()->where('order_id', $order->id)->orderBy('position')->first();
        if ($first !== null) {
            $this->startStage($order, $first, $actor, $source);
        }

        return $workflow;
    }

    /**
     * Copies stage and action definitions onto the order from `$offset` (stage positions start at 1).
     *
     * @param  list<array<string, mixed>>  $stages
     */
    public function instantiate(Order $order, array $stages, int $offset): void
    {
        $dependencies = FlowCheck::dependencyMap($this->definitionOf($order, $stages, $offset));

        foreach ($stages as $i => $definition) {
            $stage = OrderStage::query()->create([
                'order_id' => $order->id,
                'position' => $offset + $i + 1,
                'key' => $definition['key'],
                'name' => $definition['name'],
                'public_name' => $definition['public_name'] ?? null,
                'show_to_customer' => $definition['show_to_customer'] ?? true,
                'follow_up_type' => $definition['follow_up']['type'],
                'follow_up_user_id' => $this->followUpUser($order, $definition['follow_up']),
                'follow_up_department_id' => $definition['follow_up']['type'] === 'department' ? $definition['follow_up']['id'] : null,
                'target_days' => $definition['target_days'] ?? null,
                'status' => StageStatus::NotStarted,
            ]);

            foreach (array_values($definition['actions']) as $j => $action) {
                $row = new OrderAction([
                    'order_id' => $order->id,
                    'order_stage_id' => $stage->id,
                    'position' => $j + 1,
                    'key' => $action['key'],
                    'type' => $action['type'],
                    'name' => $action['name'],
                    'requirement' => $action['requirement'],
                    'applies_when' => $action['applies_when'],
                    'inputs' => $action['inputs'],
                    'depends_on' => $dependencies[$action['key']] ?? [],
                    'assign_type' => $action['assign']['type'],
                    'assign_id' => $action['assign']['id'] ?? null,
                ]);
                $row->status = $row->appliesTo($order) ? ActionStatus::Unavailable : ActionStatus::NotRequired;
                $row->save();
            }
        }
    }

    /**
     * M-06-T01: makes a stage the order's single active main stage and its available actions ready.
     */
    public function startStage(Order $order, OrderStage $stage, ?User $actor, string $source): void
    {
        Transition::check(StateMachine::M06, StageStatus::transitions(), $stage->status, 'T01');

        $active = OrderWorkflow::query()->where('order_id', $order->id)->value('active_stage_id');
        if ($active !== null) {
            Transition::refuse(StateMachine::M06, 'T01', 'another_stage_active');
        }

        $stage->forceFill(['status' => StageStatus::InProgress, 'started_at' => now()])->save();
        OrderWorkflow::query()->where('order_id', $order->id)->update(['active_stage_id' => $stage->id]);
        $this->transitions->record(StateMachine::M06, 'T01', $stage, StageStatus::NotStarted, StageStatus::InProgress, $actor, TransitionRecorder::SOURCE_SYSTEM, context: [
            'order_id' => $order->id, 'position' => $stage->position, 'by' => $source,
        ]);

        $this->refresh($order, $actor);
    }

    /**
     * Re-evaluates the active stage: dependencies met make actions ready (M-27-T01) and create their work; a result
     * recorded early is applied (M-27-T03); the waiting marker follows the open requirements (M-06-T02/T03).
     * Nothing moves while the order is paused.
     */
    public function refresh(Order $order, ?User $actor): void
    {
        if ($order->status === CustomOrderStatus::Paused->value) {
            return;
        }

        $stage = $this->activeStage($order);
        if ($stage === null) {
            return;
        }

        do {
            $changed = false;
            $all = $this->actionsOf($order);

            foreach ($all->where('order_stage_id', $stage->id) as $action) {
                if ($action->status !== ActionStatus::Unavailable || ! $this->dependenciesMet($action, $all)) {
                    continue;
                }

                $this->setStatus($action, ActionStatus::Unavailable, ActionStatus::Ready, 'T01', $actor);
                $changed = true;

                if ($action->source_reference !== null && $action->type->completion() === CompletionMode::Source) {
                    // The truth was recorded before the stage opened (SRS: early collection/approval stays valid).
                    $this->setStatus($action, ActionStatus::Ready, ActionStatus::Verified, 'T03', $actor, context: ['source' => $action->source_reference]);
                } elseif ($action->type->completion()->createsTask()) {
                    $this->tasks->forAction($order, $action, $actor, TransitionRecorder::SOURCE_SYSTEM);
                }
            }
        } while ($changed);

        $this->syncWaiting($order, $stage, $actor);
    }

    /**
     * M-27-T03: the action's result is in. Re-evaluates the active stage afterwards.
     *
     * @param  array<string, mixed>  $source
     */
    public function verify(Order $order, OrderAction $action, ?User $actor, array $source = []): void
    {
        $from = $action->status;
        Transition::check(StateMachine::M27, ActionStatus::transitions(), $from, 'T03');

        $this->setStatus($action, $from, ActionStatus::Verified, 'T03', $actor, context: $source === [] ? [] : ['source' => $source]);
        if ($source !== []) {
            $action->forceFill(['source_reference' => $source])->save();
        }

        $this->releaseHeldBy($action);
        $this->clearCorrection($order, $action->stage()->firstOrFail(), $actor);
        $this->refresh($order, $actor);
    }

    /**
     * Dependents held because this action was reworked may complete again (WF-11).
     */
    public function releaseHeldBy(OrderAction $action): void
    {
        OrderAction::query()->where('order_id', $action->order_id)->where('held_by_action_id', $action->id)->update(['held_by_action_id' => null]);
    }

    /**
     * M-06-T06 (engine): a completed stage marked for correction returns to completed once nothing in it is reworked.
     */
    public function clearCorrection(Order $order, OrderStage $stage, ?User $actor): void
    {
        if ($stage->status !== StageStatus::CompletedNeedsCorrection) {
            return;
        }

        $pending = OrderAction::query()->where('order_stage_id', $stage->id)
            ->whereIn('status', [ActionStatus::NeedsRework->value, ActionStatus::Ready->value, ActionStatus::AwaitingResult->value])
            ->where('rework_count', '>', 0)
            ->exists();
        if ($pending) {
            return;
        }

        $stage->forceFill(['status' => StageStatus::Completed])->save();
        $this->transitions->record(StateMachine::M06, 'T06', $stage, StageStatus::CompletedNeedsCorrection, StageStatus::Completed, $actor, TransitionRecorder::SOURCE_SYSTEM, context: [
            'order_id' => $order->id,
        ]);
    }

    public function activeStage(Order $order): ?OrderStage
    {
        $id = OrderWorkflow::query()->where('order_id', $order->id)->value('active_stage_id');

        return $id === null ? null : OrderStage::query()->find((int) $id);
    }

    /**
     * @param  Collection<int, OrderAction>  $all
     */
    public function dependenciesMet(OrderAction $action, Collection $all): bool
    {
        if ($action->held_by_action_id !== null) {
            return false;
        }

        foreach ($action->depends_on ?? [] as $key) {
            $dependency = $all->firstWhere('key', $key);
            if ($dependency === null) {
                continue;
            }
            // An optional action of an earlier, completed stage was let go with its stage: it no longer blocks.
            if (! $dependency->isRequired() && $dependency->order_stage_id !== $action->order_stage_id) {
                continue;
            }
            if (! $dependency->status->satisfies() || $dependency->held_by_action_id !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, OrderAction>
     */
    public function actionsOf(Order $order): Collection
    {
        return OrderAction::query()->where('order_id', $order->id)->orderBy('order_stage_id')->orderBy('position')->get();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function setStatus(OrderAction $action, ActionStatus $from, ActionStatus $to, string $code, ?User $actor, ?string $reason = null, array $context = [], string $source = TransitionRecorder::SOURCE_SYSTEM): void
    {
        $action->forceFill([
            'status' => $to,
            'verified_at' => $to === ActionStatus::Verified ? now() : $action->verified_at,
        ])->save();

        $this->transitions->record(StateMachine::M27, $code, $action, $from, $to, $actor, $source, $reason, [
            'order_id' => $action->order_id, 'action' => $action->key, ...$context,
        ]);
    }

    /**
     * M-06-T02/T03: a stage waits while a required, applicable action waits for a result from another part.
     */
    private function syncWaiting(Order $order, OrderStage $stage, ?User $actor): void
    {
        $waiting = OrderAction::query()->where('order_stage_id', $stage->id)->where('requirement', 'required')
            ->where('status', ActionStatus::AwaitingResult->value)->exists();

        if ($waiting && $stage->status === StageStatus::InProgress) {
            $stage->forceFill(['status' => StageStatus::Waiting])->save();
            $this->transitions->record(StateMachine::M06, 'T02', $stage, StageStatus::InProgress, StageStatus::Waiting, $actor, TransitionRecorder::SOURCE_SYSTEM, context: ['order_id' => $order->id]);
        } elseif (! $waiting && $stage->status === StageStatus::Waiting) {
            $stage->forceFill(['status' => StageStatus::InProgress])->save();
            $this->transitions->record(StateMachine::M06, 'T03', $stage, StageStatus::Waiting, StageStatus::InProgress, $actor, TransitionRecorder::SOURCE_SYSTEM, context: ['order_id' => $order->id]);
        }
    }

    /**
     * @param  array{type: string, id?: int}  $followUp
     */
    private function followUpUser(Order $order, array $followUp): ?int
    {
        return match ($followUp['type']) {
            'member' => $followUp['id'] ?? null,
            'order_assignee' => TaskEligibility::followerOf($order),
            default => null,
        };
    }

    /**
     * Full stage list for dependency resolution: stages already on the order (when editing upcoming work) + new ones.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    private function definitionOf(Order $order, array $stages, int $offset): array
    {
        if ($offset === 0) {
            return $stages;
        }

        return [...OrderDefinition::fixedStages($order, $offset), ...$stages];
    }
}
