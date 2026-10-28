<?php

namespace App\Calendar;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Appointment;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Shapes appointments for the calendar screens (SHR-04). Times are stored in UTC and shown in the facility time zone.
 * A user sees an appointment only with P-54 over it or as a booked member (P-24); a booked member without P-54 gets the
 * visit's own fields only (no customer name, WF-08). A clash is described only when the viewer may see the clashing
 * appointment — otherwise only its count is reported.
 */
final class CalendarPresenter
{
    public static function canManage(User $user, Appointment $appointment): bool
    {
        return Access::can($user, Permission::P54, $appointment);
    }

    public static function canSee(User $user, Appointment $appointment): bool
    {
        return self::canManage($user, $appointment) || Access::can($user, Permission::P24, $appointment);
    }

    public static function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(AppointmentBooking::timezone());
    }

    /**
     * Parses a facility-local wall time (`2026-11-03T09:00`) into UTC.
     */
    public static function parseLocal(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, AppointmentBooking::timezone())->utc();
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Appointment $a, User $viewer): array
    {
        $manage = self::canManage($viewer, $a);
        $start = self::local($a->starts_at);
        $end = self::local($a->ends_at);
        $pending = $a->pending_change;

        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'status' => $a->status->value,
            'date' => $start->format('Y-m-d'),
            'start' => $start->format('H:i'),
            'end' => $end->format('H:i'),
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'ends_at' => $end->format('Y-m-d\TH:i'),
            'order' => ['id' => $a->order_id, 'number' => $a->order->number],
            'branch' => OrgPresenter::name($a->order->branch),
            'customer' => $manage ? $a->order->customer->name : null,
            'members' => array_values($a->members->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all()),
            'conflicted' => $a->conflicted,
            'no_show' => $a->no_show,
            'has_pending_change' => $pending !== null,
            'can_manage' => $manage,
            'maps_url' => $a->mapsUrl(),
            'address' => $a->address,
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $clashes
     * @return array{conflicts: list<array<string, mixed>>, hidden: int}
     */
    public static function clashes(User $viewer, Collection $clashes): array
    {
        $clashes->each(fn (Appointment $a) => $a->loadMissing(['order.branch', 'order.customer', 'members']));
        $visible = $clashes->filter(fn (Appointment $a) => self::canSee($viewer, $a))->values();

        return [
            'conflicts' => array_values($visible->map(fn (Appointment $a) => self::row($a, $viewer))->all()),
            'hidden' => $clashes->count() - $visible->count(),
        ];
    }
}
