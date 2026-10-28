<?php

namespace App\Actions\StateMachines\M04CustomOrder;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\CustomOrderStatus;
use App\Enums\StageStatus;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Support\LockingTransaction;
use App\Workflow\Transition;
use App\Workflow\WorkflowLock;

/**
 * M-04-T03 (P-25): execution is finished when no main stage remains and no correction is open. Completing the last
 * stage applies it; after a closing correction (M-04-T08) the authorized person finishes again explicitly. The money
 * still owed stays visible (WF-09).
 */
final class T03FinishExecution
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, Order $order, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        return LockingTransaction::run(function () use ($actor, $order, $lockVersion, $source) {
            $order = WorkflowLock::order($order->id, $lockVersion);
            Access::authorize($actor, Permission::P25, $order);

            return $this->apply($actor, $order, $source);
        });
    }

    /**
     * Caller holds the order lock and checked P-25.
     */
    public function apply(User $actor, Order $order, string $source): Order
    {
        OrderTransitions::check($order, 'T03');

        $pending = OrderWorkflow::query()->where('order_id', $order->id)->whereNotNull('active_stage_id')->exists()
            || OrderStage::query()->where('order_id', $order->id)
                ->whereIn('status', [StageStatus::NotStarted->value, StageStatus::InProgress->value, StageStatus::Waiting->value, StageStatus::CompletedNeedsCorrection->value])
                ->exists();
        if ($pending) {
            Transition::refuse($order->kind->machine(), 'T03', 'work_pending');
        }

        $order->status = CustomOrderStatus::ExecutionFinished->value;
        $order->lock_version++;
        $order->save();

        $this->transitions->record($order->kind->machine(), 'T03', $order, CustomOrderStatus::InProgress, CustomOrderStatus::ExecutionFinished, $actor, $source);
        Audit::record(AuditAction::OrderExecutionFinished, $order, $actor, before: ['status' => CustomOrderStatus::InProgress->value],
            after: ['status' => CustomOrderStatus::ExecutionFinished->value], source: $source, label: $order->number);

        return $order;
    }
}
