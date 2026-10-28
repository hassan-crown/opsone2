<?php

namespace App\Actions\StateMachines\M34Cancellation;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Cancellation\CancellationNotices;
use App\Enums\CancellationStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Models\Cancellation\OrderCancellation;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\WorkGuard;

/**
 * M-34-T04 (P-18) with M-04-T06: refuse with a reason the customer understands. Only this request's suspension is
 * lifted — the order returns to the state it had before the request, and an independent pause stays (SRS-WF-13).
 */
final class T04RejectCancellation
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly CancellationNotices $notices,
    ) {}

    public function handle(User $actor, OrderCancellation $cancellation, string $reason, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): OrderCancellation
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $cancellation, $reason, $lockVersion, $source) {
            $order = OrderStock::lockOrder($cancellation->order_id);
            $locked = OrderCancellation::query()->lockForUpdate()->findOrFail($cancellation->id);
            $locked->setRelation('order', $order);
            Access::authorize($actor, Permission::P18, $order);
            T02AcceptCancellation::checkVersion($locked, $lockVersion);
            if ($locked->status !== CancellationStatus::Pending) {
                throw new InvalidTransition(StateMachine::M34, 'T04', 'not_from_'.$locked->status->value);
            }

            $locked->forceFill([
                'status' => CancellationStatus::Rejected, 'decision_reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(),
                'active_key' => null, 'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M34, 'T04', $locked, CancellationStatus::Pending, CancellationStatus::Rejected, $actor, $source, $reason, ['order_id' => $order->id]);

            if ($order->status === CustomOrderStatus::CancellationPending->value) {
                $order->status = $locked->order_status_before;
                $order->lock_version++;
                $order->save();
                $this->transitions->record(StateMachine::M04, 'T06', $order, CustomOrderStatus::CancellationPending, $locked->order_status_before, $actor, $source, $reason, [
                    'cancellation' => $locked->number,
                ]);
            }
            Audit::record(AuditAction::CancellationRejected, $order, $actor, after: ['cancellation' => $locked->number, 'status' => $order->status],
                reason: $reason, source: $source, label: $order->number);
            $this->notices->rejected($locked, $order, $actor);

            return $locked;
        });
    }
}
