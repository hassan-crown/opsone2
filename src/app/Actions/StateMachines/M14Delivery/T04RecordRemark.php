<?php

namespace App\Actions\StateMachines\M14Delivery;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DeliveryStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Models\Delivery\DeliveryNote;
use App\Models\Delivery\DeliveryRemark;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use App\Workflow\TaskEligibility;
use Illuminate\Validation\ValidationException;

/**
 * M-14-T04 (P-52): a blocking remark on a confirmed delivery. The delivery is neither repeated nor undone and the order
 * does not start over; the remark waits with a follow-up person (the order's follower) and keeps the order from closing
 * (M-04-T07 / M-05-T10) until `resolve()` records how it was settled.
 */
final class T04RecordRemark
{
    public function handle(User $actor, DeliveryNote $note, string $description): DeliveryRemark
    {
        $description = trim($description);
        if (mb_strlen($description) < 3 || mb_strlen($description) > 2000) {
            throw ValidationException::withMessages(['description' => __('delivery.remark.description_invalid')]);
        }

        return LockingTransaction::run(function () use ($actor, $note, $description) {
            $order = OrderStock::lockOrder($note->order_id);
            $locked = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);
            $locked->setRelation('order', $order);
            Access::authorize($actor, Permission::P52, $locked);
            if ($locked->status !== DeliveryStatus::Confirmed) {
                throw new InvalidTransition(StateMachine::M14, 'T04', 'not_from_'.$locked->status->value);
            }

            $remark = DeliveryRemark::query()->create([
                'delivery_note_id' => $locked->id, 'order_id' => $order->id, 'description' => $description,
                'follow_up_user_id' => TaskEligibility::followerOf($order) ?? $order->assignee_id, 'created_by' => $actor->id,
            ]);
            Audit::record(AuditAction::DeliveryRemarkRecorded, $locked, $actor, after: ['remark' => $remark->id, 'order' => $order->number], branchIds: [$order->branch_id], label: (string) $locked->number);

            return $remark;
        });
    }

    /** Settle a remark (P-52): what was done, kept with who and when. */
    public function resolve(User $actor, DeliveryRemark $remark, string $resolution, ?int $lockVersion = null): DeliveryRemark
    {
        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 3 || mb_strlen($resolution) > 2000) {
            throw ValidationException::withMessages(['resolution' => __('delivery.remark.resolution_invalid')]);
        }

        return LockingTransaction::run(function () use ($actor, $remark, $resolution, $lockVersion) {
            $order = OrderStock::lockOrder($remark->order_id);
            $locked = DeliveryRemark::query()->lockForUpdate()->findOrFail($remark->id);
            $note = DeliveryNote::query()->findOrFail($locked->delivery_note_id);
            $note->setRelation('order', $order);
            Access::authorize($actor, Permission::P52, $note);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            if (! $locked->isOpen()) {
                throw new InvalidTransition(StateMachine::M14, 'T04', 'remark_'.$locked->status);
            }

            $locked->forceFill(['status' => DeliveryRemark::RESOLVED, 'resolution' => $resolution, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
            Audit::record(AuditAction::DeliveryRemarkResolved, $note, $actor, after: ['remark' => $locked->id, 'order' => $order->number], branchIds: [$order->branch_id], label: (string) $note->number);

            return $locked;
        });
    }
}
