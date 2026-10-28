<?php

namespace App\Actions\Workflow;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\CustomOrderStatus;
use App\Enums\StageStatus;
use App\Enums\StateMachine;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\FlowCheck;
use App\Workflow\OrderDefinition;
use App\Workflow\StageEngine;
use App\Workflow\Transition;
use App\Workflow\WorkflowLock;
use App\Workflow\WorkGuard;

/**
 * P-28 «تعديل الأعمال القادمة» (WF-07, WF-11, `OrderEditUpcoming`): replace the stages of THIS order that have not
 * started, with a reason, after FlowCheck re-checks them against the stages already started. Started or completed
 * work, results and references are never edited here; the facility flow and other orders are untouched.
 */
final class EditUpcomingWork
{
    public function __construct(private readonly FlowCheck $check, private readonly StageEngine $engine) {}

    /**
     * @param  list<array<string, mixed>>  $upcoming  the new definition of every not-started stage, in order
     */
    public function handle(User $actor, Order $order, array $upcoming, string $reason, int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $order, $upcoming, $reason, $lockVersion, $source) {
            $order = WorkflowLock::order($order->id, $lockVersion);
            Access::authorize($actor, Permission::P28, $order);
            if (! in_array($order->status, [CustomOrderStatus::InProgress->value, CustomOrderStatus::Paused->value], true)) {
                Transition::refuse(StateMachine::M04, 'P28', 'order_not_running');
            }

            $stages = OrderStage::query()->where('order_id', $order->id)->orderBy('position')->get();
            $started = $stages->filter(fn (OrderStage $s) => $s->status !== StageStatus::NotStarted);
            $pending = $stages->filter(fn (OrderStage $s) => $s->status === StageStatus::NotStarted);
            $lastStarted = (int) ($started->max('position') ?? 0);
            if ($pending->contains(fn (OrderStage $s) => $s->position < $lastStarted)) {
                Transition::refuse(StateMachine::M04, 'P28', 'stage_order_broken');
            }

            // A reference recorded early (collection, approval) must not be dropped by an edit.
            $referenced = OrderAction::query()->whereIn('order_stage_id', $pending->pluck('id'))->whereNotNull('source_reference')->pluck('key')->all();
            $result = $this->check->run(['stages' => $upcoming], OrderDefinition::fixedStages($order, $lastStarted));
            $result->throwIfFails('upcoming.');
            $kept = [];
            foreach ($result->stages as $stage) {
                foreach ($stage['actions'] as $action) {
                    $kept[] = $action['key'];
                }
            }
            if (array_diff($referenced, $kept) !== []) {
                Transition::refuse(StateMachine::M04, 'P28', 'has_reference');
            }
            $references = OrderAction::query()->whereIn('order_stage_id', $pending->pluck('id'))->whereNotNull('source_reference')
                ->pluck('source_reference', 'key')->all();

            $before = array_map(fn (OrderStage $s) => OrderDefinition::stage($s), $pending->values()->all());
            OrderAction::query()->whereIn('order_stage_id', $pending->pluck('id'))->delete();
            OrderStage::query()->whereIn('id', $pending->pluck('id'))->delete();

            $this->engine->instantiate($order, $result->stages, $lastStarted);
            foreach ($references as $key => $reference) {
                OrderAction::query()->where('order_id', $order->id)->where('key', $key)->update(['source_reference' => json_encode($reference)]);
            }

            Audit::record(AuditAction::UpcomingWorkEdited, $order, $actor, before: ['stages' => $before], after: ['stages' => $result->stages],
                reason: $reason, source: $source, label: $order->number);
            WorkflowLock::touch($order);

            return $order;
        });
    }
}
