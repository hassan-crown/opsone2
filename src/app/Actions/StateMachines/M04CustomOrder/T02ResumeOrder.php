<?php

namespace App\Actions\StateMachines\M04CustomOrder;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\CustomOrderStatus;
use App\Models\Order;
use App\Models\OrderWorkflow;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Support\LockingTransaction;
use App\Workflow\StageEngine;
use App\Workflow\WorkflowLock;

/**
 * M-04-T02 (P-26): resume. Affected requirements are re-checked (results recorded meanwhile apply now) without
 * resetting times or repeating any effect.
 */
final class T02ResumeOrder
{
    public function __construct(private readonly TransitionRecorder $transitions, private readonly StageEngine $engine) {}

    public function handle(User $actor, Order $order, ?string $note = null, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        return LockingTransaction::run(function () use ($actor, $order, $note, $lockVersion, $source) {
            $order = WorkflowLock::order($order->id, $lockVersion);
            Access::authorize($actor, Permission::P26, $order);
            OrderTransitions::check($order, 'T02');

            $order->status = CustomOrderStatus::InProgress->value;
            $order->lock_version++;
            $order->save();
            $workflow = OrderWorkflow::query()->where('order_id', $order->id)->first();
            $pause = ['reason' => $workflow?->pause_reason, 'follow_up_user_id' => $workflow?->pause_follow_up_id];
            $workflow?->forceFill(['pause_reason' => null, 'pause_follow_up_id' => null, 'pause_review_on' => null, 'paused_from' => null])->save();

            $this->transitions->record($order->kind->machine(), 'T02', $order, CustomOrderStatus::Paused, CustomOrderStatus::InProgress, $actor, $source, $note, ['pause' => $pause]);
            Audit::record(AuditAction::OrderResumed, $order, $actor, before: ['status' => CustomOrderStatus::Paused->value],
                after: ['status' => CustomOrderStatus::InProgress->value], reason: $note, source: $source, label: $order->number);

            $this->engine->refresh($order, $actor);

            return $order;
        });
    }
}
