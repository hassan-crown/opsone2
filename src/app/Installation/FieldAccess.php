<?php

namespace App\Installation;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\AppointmentStatus;
use App\FieldSales\FieldSalesConfig;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees which visit in the field app: only the members booked on the appointment, and only while they hold the
 * kind's permission over it — P-24 for an installation (ADR-0036), P-54 for a sales visit or measurement when field
 * sales is on and the settings hand that kind to reps (ADR-0041). A manager's wider scope does not open someone
 * else's visit here — the calendar and the order page are the manager's views.
 */
final class FieldAccess
{
    /** Appointment states the field worker works from (proposed ones are not booked yet; cancelled are gone). */
    public const VISIBLE = [AppointmentStatus::AwaitingConfirmation, AppointmentStatus::Confirmed, AppointmentStatus::Done];

    public const INSTALLATION = 'installation';

    /**
     * Appointment kinds the field app shows now: installations always, the sales kinds per the field-sales settings.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [self::INSTALLATION, ...FieldSalesConfig::kinds()];
    }

    public static function isSalesKind(string $kind): bool
    {
        return in_array($kind, FieldSalesConfig::kinds(), true);
    }

    /**
     * The viewer's own field appointments between two UTC moments.
     *
     * @return Builder<Appointment>
     */
    public static function mine(User $user, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Appointment::query()
            ->whereIn('kind', self::kinds())
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, self::VISIBLE))
            ->whereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')->orderBy('id');
    }

    public static function isMember(User $user, Appointment $appointment): bool
    {
        if (! in_array($appointment->status, self::VISIBLE, true) || ! in_array($user->id, $appointment->memberIds(), true)) {
            return false;
        }
        if ($appointment->kind === self::INSTALLATION) {
            return Access::can($user, Permission::P24, $appointment);
        }

        return self::isSalesKind($appointment->kind) && Access::can($user, Permission::P54, $appointment);
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

    /**
     * An installation the viewer works (FieldVisits steps refuse a sales visit).
     *
     * @throws AuthorizationException
     */
    public static function authorizeInstallation(User $user, Appointment $appointment): void
    {
        if ($appointment->kind !== self::INSTALLATION) {
            throw new AuthorizationException(__('access.denied.no_grant'));
        }
        self::authorize($user, $appointment);
    }
}
