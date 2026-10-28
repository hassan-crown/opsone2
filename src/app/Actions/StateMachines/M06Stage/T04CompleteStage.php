<?php

namespace App\Actions\StateMachines\M06Stage;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M04CustomOrder\T03FinishExecution;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ActionStatus;
use App\Enums\StageStatus;
use App\Enums\StateMachine;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\RequirementsOpen;
use App\Workflow\StageEngine;
use App\Workflow\Transition;
use App\Workflow\WorkflowLock;
use App\Workflow\WorkGuard;

/**
 * M-06-T04 (P-25 over the stage; Q-30 a: a supervisor within scope may complete): every required, applicable action is
 * verified or covered by a valid allowance; optional open work stays under follow-up. Opens the next stage once (M-06-
 * T01); after the last one the order's execution is finished (M-04-T03, same permission).
 */
final class T04CompleteStage
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly StageEngine $engine,
        private readonly T03FinishExecution $finish,
    ) {}

    public function handle(User $actor, OrderStage $stage, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): OrderStage
    {
        return LockingTransaction::run(function () use ($actor, $stage, $lockVersion, $source) {
            $order = WorkflowLock::order($stage->order_id, $lockVersion);
            $stage = OrderStage::query()->findOrFail($stage->id);
            $stage->setRelation('order', $order);
            Access::authorize($actor, Permission::P25, $stage);
            $from = $stage->status;
            Transition::check(StateMachine::M06, StageStatus::transitions(), $from, 'T04');
            WorkGuard::running($order, StateMachine::M06, 'T04');

            if (OrderWorkflow::query()->where('order_id', $order->id)->value('active_stage_id') !== $stage->id) {
                Transition::refuse(StateMachine::M06, 'T04', 'not_active_stage');
            }

            $open = OrderAction::query()->where('order_stage_id', $stage->id)->orderBy('position')->get()
                ->filter(fn (OrderAction $a) => $a->held_by_action_id !== null
                    || ($a->isRequired() && ! $a->status->satisfies())
                    || $a->status === ActionStatus::NeedsRework)
                ->map(fn (OrderAction $a) => $a->key)->all();
            $open = array_values($open);
            if ($open !== []) {
                throw new RequirementsOpen($open);
            }

            $stage->forceFill(['status' => StageStatus::Completed, 'completed_at' => now(), 'completed_by' => $actor->id])->save();
            OrderWorkflow::query()->where('order_id', $order->id)->update(['active_stage_id' => null]);
            $this->transitions->record(StateMachine::M06, 'T04', $stage, $from, StageStatus::Completed, $actor, $source, context: ['order_id' => $order->id]);
            Audit::record(AuditAction::StageCompleted, $order, $actor, after: ['stage' => $stage->key], source: $source, label: $order->number);
            WorkflowLock::touch($order);

            $next = OrderStage::query()->where('order_id', $order->id)->where('position', '>', $stage->position)->orderBy('position')->first();
            if ($next !== null) {
                $this->engine->startStage($order, $next, $actor, $source);
            } else {
                $this->finish->apply($actor, $order, $source);
            }

            return $stage->refresh();
        });
    }
}
