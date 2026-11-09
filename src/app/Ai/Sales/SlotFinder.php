<?php

namespace App\Ai\Sales;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\AppointmentStatus;
use App\Enums\MembershipStatus;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Task;
use App\Models\WorkSettings;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Measurement slots offered to a new lead, computed from the real calendar and never by the model (plan §5.1):
 *
 * - only working days and working hours of the facility (WorkSettings, holidays, facility time zone), on the slot grid
 *   (start of work + n × slot length), never in the past and never closer than MIN_LEAD_MINUTES;
 * - the resources are the branch's active staff who could take a task there (P-24) — support accounts never;
 * - a member is busy while he has a confirmed appointment or one waiting for the customer's answer overlapping the
 *   slot; a slot is offered only when at least one member is free (the lowest id, so the answer is stable).
 *
 * The same grid check (`validate`) guards the booking: a slot the finder would not offer cannot be booked from here.
 * The final, atomic conflict check stays in the M-16 Actions.
 */
final class SlotFinder
{
    public const MIN_LEAD_MINUTES = 120;

    public const HORIZON_DAYS = 14;

    public const MAX_PER_DAY = 3;

    public const LIMIT = 6;

    private const DAY_NAMES = [1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت', 7 => 'الأحد'];

    /**
     * @return list<Slot>
     */
    public function find(int $branchId, ?CarbonInterface $now = null, int $limit = self::LIMIT): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $members = $this->members($branchId);
        if ($members === []) {
            return [];
        }

        $slots = [];
        $perDay = [];
        foreach ($this->grid($now) as $start => $end) {
            $startsAt = CarbonImmutable::parse($start)->utc();
            $endsAt = CarbonImmutable::parse($end)->utc();
            $day = $startsAt->setTimezone(AppointmentBooking::timezone())->toDateString();
            if (($perDay[$day] ?? 0) >= self::MAX_PER_DAY) {
                continue;
            }
            $free = $this->freeMember($members, $startsAt, $endsAt);
            if ($free === null) {
                continue;
            }
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            $slots[] = new Slot($startsAt, $endsAt, $free, self::label($startsAt));
            if (count($slots) >= $limit) {
                break;
            }
        }

        return $slots;
    }

    /**
     * Whether this member is free for exactly this slot, and the slot lies on the grid. Returns the slot, or null.
     */
    public function validate(int $branchId, CarbonInterface $startsAt, int $memberId, ?CarbonInterface $now = null): ?Slot
    {
        $now = CarbonImmutable::instance($now ?? now());
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        if (! in_array($memberId, $this->members($branchId), true)) {
            return null;
        }
        foreach ($this->grid($now) as $start => $end) {
            $candidate = CarbonImmutable::parse($start)->utc();
            if ($candidate->equalTo($startsAt)) {
                $endsAt = CarbonImmutable::parse($end)->utc();

                return $this->freeMember([$memberId], $candidate, $endsAt) === null ? null : new Slot($candidate, $endsAt, $memberId, self::label($candidate));
            }
        }

        return null;
    }

    public static function label(CarbonInterface $startsAt): string
    {
        $local = CarbonImmutable::instance($startsAt)->setTimezone(AppointmentBooking::timezone());

        return self::DAY_NAMES[$local->isoWeekday()].' '.$local->format('Y-m-d').' '.$local->format('H:i');
    }

    /**
     * Every grid slot of the horizon as [start ISO => end ISO], in time order.
     *
     * @return array<string, string>
     */
    private function grid(CarbonImmutable $now): array
    {
        $settings = WorkSettings::current();
        $tz = AppointmentBooking::timezone();
        $length = max(15, $settings->appointment_slot_minutes);
        $holidays = array_map('strval', (array) ($settings->holidays ?? []));
        $earliest = $now->addMinutes(self::MIN_LEAD_MINUTES);
        $out = [];

        for ($i = 0; $i <= self::HORIZON_DAYS; $i++) {
            $day = $now->setTimezone($tz)->startOfDay()->addDays($i);
            if (! $settings->isWorkingDay($day) || in_array($day->toDateString(), $holidays, true)) {
                continue;
            }
            $open = $day->setTimeFromTimeString($settings->work_starts_at);
            $close = $day->setTimeFromTimeString($settings->work_ends_at);
            for ($start = $open; $start->addMinutes($length)->lessThanOrEqualTo($close); $start = $start->addMinutes($length)) {
                if ($start->lessThan($earliest)) {
                    continue;
                }
                $out[$start->utc()->toIso8601String()] = $start->addMinutes($length)->utc()->toIso8601String();
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function members(int $branchId): array
    {
        // A probe order only carries the branch: the same P-24 check the booking applies to a real order.
        $probeOrder = new Order(['branch_id' => $branchId]);
        $ids = [];
        $memberships = Membership::query()->where('status', MembershipStatus::Active->value)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
            ->with('user')->orderBy('user_id')->get();
        foreach ($memberships as $membership) {
            $user = $membership->user;
            if ($user->is_support) {
                continue;
            }
            $probe = new Task(['assignee_id' => $user->id, 'status' => TaskStatus::Assigned]);
            $probe->setRelation('order', $probeOrder);
            if (Access::can($user, Permission::P24, $probe)) {
                $ids[] = (int) $user->id;
            }
        }

        return $ids;
    }

    /**
     * The lowest-id member among these who has no blocking appointment overlapping the window, or null.
     *
     * @param  list<int>  $members
     */
    private function freeMember(array $members, CarbonImmutable $startsAt, CarbonImmutable $endsAt): ?int
    {
        $busy = Appointment::query()
            ->whereIn('status', [AppointmentStatus::Confirmed->value, AppointmentStatus::AwaitingConfirmation->value])
            ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)
            ->with('members:id')->get()
            ->flatMap(fn (Appointment $a) => $a->members->pluck('id'))->map(intval(...))->all();

        foreach ($members as $id) {
            if (! in_array($id, $busy, true)) {
                return $id;
            }
        }

        return null;
    }
}
