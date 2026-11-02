<?php

namespace App\Installation;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees which installation visit in the field app (ADR-0036): only the members booked on the appointment, and
 * only while they hold P-24 over it. A manager's wider scope does not open someone else's visit here — the
 * calendar and the order page are the manager's views.
 */
final class FieldAccess
{
    /** Appointment states the technician works from (proposed ones are not booked yet; cancelled are gone). */
    public const VISIBLE = [AppointmentStatus::AwaitingConfirmation, AppointmentStatus::Confirmed, AppointmentStatus::Done];

    /**
     * The viewer's own installation appointments between two UTC moments.
     *
     * @return Builder<Appointment>
     */
    public static function mine(User $user, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Appointment::query()
            ->where('kind', 'installation')
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, self::VISIBLE))
            ->whereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')->orderBy('id');
    }

    public static function isMember(User $user, Appointment $appointment): bool
    {
        return $appointment->kind === 'installation'
            && in_array($appointment->status, self::VISIBLE, true)
            && in_array($user->id, $appointment->memberIds(), true)
            && Access::can($user, Permission::P24, $appointment);
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(User $user, Appointment $appointment): void
    {
        if (! self::isMember($user, $appointment)) {
            throw new AuthorizationException(__('access.denied.no_grant'));
        }
    }
}
