<?php

namespace App\Workflow;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Calendar reads (SHR-04, `CalWeek` and the daily view): appointments in a window that the user may manage (P-54 in
 * scope) or is booked on (P-24). Times are UTC; screens show them in the facility time zone.
 */
final class AppointmentQueries
{
    public const LIMIT = 500;

    /**
     * @param  list<int>|null  $memberIds  only appointments of these members
     * @return Collection<int, Appointment>
     */
    public static function between(User $user, CarbonInterface $from, CarbonInterface $to, ?array $memberIds = null): Collection
    {
        return Appointment::query()->with(['order', 'members'])
            ->whereIn('status', [AppointmentStatus::Proposed->value, AppointmentStatus::AwaitingConfirmation->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Done->value])
            ->where('starts_at', '<', $to->copy()->utc())
            ->where('ends_at', '>', $from->copy()->utc())
            ->when($memberIds !== null, fn ($q) => $q->whereHas('members', fn ($m) => $m->whereIn('users.id', $memberIds)))
            ->orderBy('starts_at')->limit(self::LIMIT)->get()
            ->filter(fn (Appointment $a) => Access::can($user, Permission::P54, $a) || Access::can($user, Permission::P24, $a))
            ->values();
    }
}
