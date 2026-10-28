<?php

namespace App\Actions\StateMachines\M04CustomOrder;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\CustomOrderStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderWorkflow;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Support\LockingTransaction;
use App\Workflow\WorkflowLock;
use App\Workflow\WorkGuard;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * M-04-T01 (P-26): pause with a reason and a follow-up person. The stage, appointments and results are kept; money
 * matters stay open (WF-09). Task work and stage completion wait until it resumes.
 */
final class T01PauseOrder
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, Order $order, string $reason, int $followUpUserId, ?CarbonInterface $reviewOn = null, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $order, $reason, $followUpUserId, $reviewOn, $lockVersion, $source) {
            $order = WorkflowLock::order($order->id, $lockVersion);
            Access::authorize($actor, Permission::P26, $order);
            OrderTransitions::check($order, 'T01');

            $follower = User::query()->find($followUpUserId);
            if ($follower === null || $follower->is_support || ! Access::can($follower, Permission::P16, $order)
                || Membership::query()->where('user_id', $followUpUserId)->where('status', MembershipStatus::Active)->doesntExist()) {
                throw ValidationException::withMessages(['follow_up_user_id' => __('workflow.validation.follower_invalid')]);
            }

            $order->status = CustomOrderStatus::Paused->value;
            $order->lock_version++;
            $order->save();
            OrderWorkflow::query()->where('order_id', $order->id)->update([
                'pause_reason' => $reason,
                'pause_follow_up_id' => $followUpUserId,
                'pause_review_on' => $reviewOn?->toDateString(),
                'paused_from' => CustomOrderStatus::InProgress->value,
            ]);

            $this->transitions->record($order->kind->machine(), 'T01', $order, CustomOrderStatus::InProgress, CustomOrderStatus::Paused, $actor, $source, $reason, [
                'follow_up_user_id' => $followUpUserId, 'review_on' => $reviewOn?->toDateString(),
            ]);
            Audit::record(AuditAction::OrderPaused, $order, $actor, before: ['status' => CustomOrderStatus::InProgress->value],
                after: ['status' => CustomOrderStatus::Paused->value, 'follow_up_user_id' => $followUpUserId], reason: $reason, source: $source, label: $order->number);

            return $order;
        });
    }
}
