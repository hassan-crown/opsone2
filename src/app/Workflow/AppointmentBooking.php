<?php

namespace App\Workflow;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\AppointmentStatus;
use App\Enums\MembershipStatus;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSettings;
use App\Notifications\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Resource booking for appointments (SHR-03): members are the resources; a team is checked member by member.
 *
 * Atomic conflict check: confirming or moving an appointment first locks the membership rows of its members, in id
 * order, as the transaction's first read (App\Support\LockingTransaction). Every other booking of any of those members
 * queues on the same rows, so the overlap query and the write cannot interleave: of two concurrent confirmations for
 * the same member and time, the second sees the first. A proposal never reserves time.
 */
final class AppointmentBooking
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  list<int>  $memberIds
     */
    public function lockMembers(array $memberIds): void
    {
        $ids = array_values(array_unique($memberIds));
        sort($ids);
        $locked = Membership::query()->whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();

        if ($locked->count() !== count($ids)) {
            throw new LogicException('Every booked member has a membership.');
        }
    }

    /**
     * Confirmed appointments of any of these members overlapping [starts, ends).
     *
     * @param  list<int>  $memberIds
     * @return Collection<int, Appointment>
     */
    public function conflicts(array $memberIds, CarbonInterface $startsAt, CarbonInterface $endsAt, ?int $excludeId = null): Collection
    {
        return Appointment::query()
            ->where('status', AppointmentStatus::Confirmed->value)
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->where('starts_at', '<', $endsAt->copy()->utc())
            ->where('ends_at', '>', $startsAt->copy()->utc())
            ->whereHas('members', fn ($q) => $q->whereIn('users.id', $memberIds))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Applies the facility policy to a set of conflicts: none → fine; «block» → refused; «allow with reason» → needs a
     * non-empty reason from the P-54 holder. Returns the reason to keep with the confirmation.
     *
     * @param  Collection<int, Appointment>  $conflicts
     */
    public function decide(Collection $conflicts, ?string $reason, string $transition): ?string
    {
        if ($conflicts->isEmpty()) {
            return null;
        }

        $summary = array_values($conflicts->map(fn (Appointment $a) => [
            'id' => $a->id,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'member_ids' => $a->memberIds(),
        ])->all());

        if (! WorkSettings::current()->allowsConflicts()) {
            throw new AppointmentConflict($transition, 'conflict_blocked', $summary);
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new AppointmentConflict($transition, 'conflict_reason_required', $summary);
        }

        return $reason;
    }

    /**
     * Recomputes the «متعارض» marker of confirmed appointments of these members around a window (after a confirm,
     * move or cancel). The accepted reason and its history are never erased.
     *
     * @param  list<int>  $memberIds
     */
    public function refreshMarkers(array $memberIds, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        foreach ($this->conflicts($memberIds, $startsAt, $endsAt) as $appointment) {
            $clash = $this->conflicts($appointment->memberIds(), $appointment->starts_at, $appointment->ends_at, $appointment->id)->isNotEmpty();
            if ($appointment->conflicted !== $clash) {
                $appointment->forceFill(['conflicted' => $clash])->save();
            }
        }
    }

    /**
     * A bookable member: active staff (never support) who could work on this order's tasks (P-24 in its branch).
     *
     * @param  list<int>  $memberIds
     * @return list<int>
     */
    public function validMembers(Order $order, array $memberIds): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $memberIds)));
        if ($ids === [] || count($ids) > 10) {
            throw ValidationException::withMessages(['member_ids' => __('workflow.validation.members_invalid')]);
        }

        foreach ($ids as $id) {
            $user = User::query()->find($id);
            $active = Membership::query()->where('user_id', $id)->where('status', MembershipStatus::Active)->exists();
            $probe = new Task(['order_id' => $order->id, 'assignee_id' => $id, 'status' => TaskStatus::Assigned]);
            $probe->setRelation('order', $order);
            if ($user === null || $user->is_support || ! $active || ! Access::can($user, Permission::P24, $probe)) {
                throw ValidationException::withMessages(['member_ids' => __('workflow.validation.members_invalid')]);
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Notifies the booked members (and, for a conflict, the members of the clashing appointments).
     *
     * @param  list<int>  $userIds
     */
    public function notify(Appointment $appointment, string $type, array $userIds): void
    {
        $params = [
            'order' => $appointment->order->number,
            'starts_at' => $appointment->starts_at->copy()->setTimezone(self::timezone())->format('Y-m-d H:i'),
        ];
        foreach (User::query()->whereIn('id', array_unique($userIds))->get() as $user) {
            $this->notifier->send($user, $type, $params, $appointment, "appointment:{$appointment->id}:{$type}:{$appointment->lock_version}:{$user->id}");
        }
    }

    public static function timezone(): string
    {
        return Facility::current()?->timezone ?: (string) config('app.timezone');
    }
}
