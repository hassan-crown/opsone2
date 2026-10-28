<?php

namespace App\Actions\StateMachines\M14Delivery;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Delivery\DeliveryWrite;
use App\Enums\DeliveryStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Models\Delivery\DeliveryNote;
use App\Models\User;
use App\Support\StaleRecord;
use App\Workflow\WorkGuard;

/**
 * M-14-T02 (P-52): withdraw a draft before confirmation — no stock was touched and no number was taken.
 */
final class T02CancelDraft
{
    public function __construct(private readonly DeliveryWrite $write, private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, DeliveryNote $note, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): DeliveryNote
    {
        $reason = WorkGuard::reason($reason);

        $this->write->run('cancel', $actor, $idempotencyKey, ['note' => $note->id, 'reason' => $reason, 'lock_version' => $lockVersion], function () use ($actor, $note, $reason, $lockVersion, $source) {
            $order = OrderStock::lockOrder($note->order_id);
            $locked = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);
            $locked->setRelation('order', $order);
            Access::authorize($actor, Permission::P52, $locked);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            if ($locked->status !== DeliveryStatus::Draft) {
                throw new InvalidTransition(StateMachine::M14, 'T02', 'not_from_'.$locked->status->value);
            }
            $locked->forceFill(['status' => DeliveryStatus::Cancelled, 'cancel_reason' => $reason, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();

            $this->transitions->record(StateMachine::M14, 'T02', $locked, DeliveryStatus::Draft, DeliveryStatus::Cancelled, $actor, $source, $reason);
            Audit::record(AuditAction::DeliveryCancelled, $locked, $actor, reason: $reason, source: $source, branchIds: [$order->branch_id], label: $order->number);

            return ['note_id' => $locked->id];
        });

        return $note->refresh();
    }
}
