<?php

namespace App\Installation;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Calendar\CalendarPresenter;
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
 * Remarks from site (ADR-0036) on the multi-remark model of the delivery remarks (ADR-0032/0035): an order holds any
 * number; every one (missing part, damage, extra work…) is `open` with a follow-up person — the order's follower,
 * else its responsible person — who is notified, and keeps the order from closing (OrderClosure) until someone with
 * P-52 over the order resolves it on its own. `row()` has the keys of `DeliveryRemarks::row()` plus the visit.
 */
final class InstallationRemarks
{
    public function __construct(private readonly Notifier $notifier) {}

    public function record(User $actor, InstallationVisit $visit, string $kind, string $description): InstallationRemark
    {
        if (! in_array($kind, InstallationRemark::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('installation.error.kind')]);
        }
        $description = trim($description);
        if (mb_strlen($description) < 3 || mb_strlen($description) > 2000) {
            throw ValidationException::withMessages(['description' => __('installation.error.description')]);
        }

        return LockingTransaction::run(function () use ($actor, $visit, $kind, $description) {
            $order = Order::query()->lockForUpdate()->findOrFail($visit->order_id);
            $locked = InstallationVisit::query()->lockForUpdate()->findOrFail($visit->id);
            $appointment = Appointment::query()->findOrFail($locked->appointment_id);
            $appointment->setRelation('order', $order);
            FieldAccess::authorize($actor, $appointment);
            if ($locked->isFinished()) {
                throw new FieldRefused('already_finished');
            }

            $followUp = TaskEligibility::followerOf($order) ?? $order->assignee_id;
            $remark = InstallationRemark::query()->create([
                'installation_visit_id' => $locked->id, 'order_id' => $order->id, 'kind' => $kind, 'description' => $description,
                'status' => InstallationRemark::OPEN,
                'follow_up_user_id' => $followUp, 'created_by' => $actor->id,
            ]);
            Audit::record(AuditAction::InstallationRemarkRecorded, $order, $actor, after: [
                'remark' => $remark->id, 'kind' => $kind, 'appointment' => $locked->appointment_id,
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

    /** Settle a remark (P-52 over the order): what was done, kept with who and when. */
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

    /**
     * The open remarks of an order as closure-blocker items (same shape as the delivery remarks' items); `note` names
     * the visit by its date.
     *
     * @return list<array{id: int, note: string|null, description: string}>
     */
    public static function openItems(int $orderId): array
    {
        return array_values(InstallationRemark::query()->where('order_id', $orderId)->where('status', InstallationRemark::OPEN)
            ->with('visit.appointment:id,starts_at')->orderBy('id')->get()
            ->map(fn (InstallationRemark $r) => [
                'id' => $r->id, 'description' => $r->description,
                'note' => __('installation.remark.item_note', ['date' => CalendarPresenter::local($r->visit->appointment->starts_at)->toDateString()]),
            ])->all());
    }

    /**
     * Every remark of the order: open first (oldest first), then resolved (latest first) — like DeliveryRemarks.
     *
     * @return list<array<string, mixed>>
     */
    public static function forOrder(Order $order): array
    {
        $remarks = InstallationRemark::query()->where('order_id', $order->id)
            ->with(['creator:id,name', 'resolver:id,name', 'followUp:id,name'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [InstallationRemark::OPEN])
            ->orderByRaw('CASE WHEN status = ? THEN id ELSE -id END', [InstallationRemark::OPEN])
            ->get();

        return array_values($remarks->map(fn (InstallationRemark $r) => self::row($r))->all());
    }

    /**
     * The keys of `DeliveryRemarks::row()` (without the delivery note) plus the visit and the kind.
     *
     * @return array<string, mixed>
     */
    public static function row(InstallationRemark $r): array
    {
        return [
            'id' => $r->id, 'installation_visit_id' => $r->installation_visit_id, 'kind' => $r->kind,
            'description' => $r->description, 'status' => $r->status,
            'recorded_by' => $r->creator?->name, 'recorded_at' => $r->created_at->toIso8601String(),
            'follow_up' => $r->followUp?->name,
            'resolution' => $r->resolution, 'resolved_by' => $r->resolver?->name, 'resolved_at' => $r->resolved_at?->toIso8601String(),
            'lock_version' => $r->lock_version,
        ];
    }
}
