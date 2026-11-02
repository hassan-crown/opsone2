<?php

namespace App\Installation;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Appointment;
use App\Models\Installation\InstallationRemark;
use App\Models\Installation\InstallationVisit;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use App\Workflow\TaskEligibility;
use Illuminate\Validation\ValidationException;

/**
 * Remarks from site (ADR-0036), reusing the delivery-remark concept of ADR-0032: a blocking remark (missing part,
 * damage, extra work…) is `open` with a follow-up person — the order's follower, else its responsible person — who is
 * notified, and it keeps the order from closing (OrderClosure) until someone with P-52 over the order resolves it. A
 * non-blocking remark is only noted. Each remark is resolved on its own.
 */
final class InstallationRemarks
{
    public function __construct(private readonly Notifier $notifier) {}

    public function record(User $actor, InstallationVisit $visit, string $kind, string $description, bool $blocking): InstallationRemark
    {
        if (! in_array($kind, InstallationRemark::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('installation.error.kind')]);
        }
        $description = trim($description);
        if (mb_strlen($description) < 3 || mb_strlen($description) > 2000) {
            throw ValidationException::withMessages(['description' => __('installation.error.description')]);
        }

        return LockingTransaction::run(function () use ($actor, $visit, $kind, $description, $blocking) {
            $order = Order::query()->lockForUpdate()->findOrFail($visit->order_id);
            $locked = InstallationVisit::query()->lockForUpdate()->findOrFail($visit->id);
            $appointment = Appointment::query()->findOrFail($locked->appointment_id);
            $appointment->setRelation('order', $order);
            FieldAccess::authorize($actor, $appointment);
            if ($locked->isFinished()) {
                throw new FieldRefused('already_finished');
            }

            $followUp = $blocking ? (TaskEligibility::followerOf($order) ?? $order->assignee_id) : null;
            $remark = InstallationRemark::query()->create([
                'installation_visit_id' => $locked->id, 'order_id' => $order->id, 'kind' => $kind, 'description' => $description,
                'blocking' => $blocking, 'status' => $blocking ? InstallationRemark::OPEN : InstallationRemark::NOTED,
                'follow_up_user_id' => $followUp, 'created_by' => $actor->id,
            ]);
            Audit::record(AuditAction::InstallationRemarkRecorded, $order, $actor, after: [
                'remark' => $remark->id, 'kind' => $kind, 'blocking' => $blocking, 'appointment' => $locked->appointment_id,
            ], branchIds: [$order->branch_id], label: $order->number);

            $person = $followUp === null ? null : User::query()->find($followUp);
            if ($person !== null && $person->id !== $actor->id) {
                $this->notifier->send($person, 'installation.remark_blocking', [
                    'order' => $order->number, 'kind' => __('installation.remark.kind.'.$kind, [], $person->locale),
                ], $order, "installation-remark:{$remark->id}");
            }

            return $remark;
        });
    }

    /** Settle a blocking remark (P-52 over the order): what was done, kept with who and when. */
    public function resolve(User $actor, InstallationRemark $remark, string $resolution, ?int $lockVersion = null): InstallationRemark
    {
        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 3 || mb_strlen($resolution) > 2000) {
            throw ValidationException::withMessages(['resolution' => __('installation.error.resolution')]);
        }

        return LockingTransaction::run(function () use ($actor, $remark, $resolution, $lockVersion) {
            $order = Order::query()->lockForUpdate()->findOrFail($remark->order_id);
            $locked = InstallationRemark::query()->lockForUpdate()->findOrFail($remark->id);
            Access::authorize($actor, Permission::P52, $order);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            if (! $locked->isOpen()) {
                throw new FieldRefused('remark_'.$locked->status);
            }

            $locked->forceFill(['status' => InstallationRemark::RESOLVED, 'resolution' => $resolution, 'resolved_by' => $actor->id,
                'resolved_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
            Audit::record(AuditAction::InstallationRemarkResolved, $order, $actor, after: ['remark' => $locked->id],
                branchIds: [$order->branch_id], label: $order->number);

            return $locked;
        });
    }

    /** How many blocking remarks of the order are still open (the closure blocker). */
    public static function openCount(int $orderId): int
    {
        return InstallationRemark::query()->where('order_id', $orderId)->where('status', InstallationRemark::OPEN)->count();
    }
}
