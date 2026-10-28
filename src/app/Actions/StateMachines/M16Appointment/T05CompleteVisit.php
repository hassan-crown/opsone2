<?php

namespace App\Actions\StateMachines\M16Appointment;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\AuditAction;
use App\Enums\AppointmentStatus;
use App\Enums\StateMachine;
use App\Models\Appointment;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentLog;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * M-16-T05 (P-54, or a booked member holding P-24): record the visit's actual outcome. A no-show or lateness is a
 * follow-up marker on the confirmed appointment, never «تم».
 */
final class T05CompleteVisit
{
    public function __construct(private readonly AppointmentLog $log) {}

    public function handle(User $actor, Appointment $appointment, string $outcome, bool $noShow = false, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Appointment
    {
        $outcome = WorkGuard::reason($outcome, 'outcome');

        return LockingTransaction::run(function () use ($actor, $appointment, $outcome, $noShow, $lockVersion, $source) {
            $appointment = $this->log->lock($appointment, $lockVersion);
            $member = in_array($actor->id, $appointment->memberIds(), true) && Access::can($actor, Permission::P24, $appointment);
            if (! $member && ! Access::can($actor, Permission::P54, $appointment)) {
                throw new AuthorizationException(__('access.denied.no_grant'));
            }
            Transition::check(StateMachine::M16, AppointmentStatus::transitions(), $appointment->status, 'T05');

            if ($noShow) {
                $appointment->forceFill(['no_show' => true, 'outcome' => $outcome]);
                $this->log->record($appointment, null, AppointmentStatus::Confirmed, AppointmentStatus::Confirmed, $actor, $source, AuditAction::AppointmentCompleted,
                    $outcome, after: ['no_show' => true]);

                return $appointment;
            }

            $appointment->forceFill(['status' => AppointmentStatus::Done, 'outcome' => $outcome, 'no_show' => false]);
            $this->log->record($appointment, 'T05', AppointmentStatus::Confirmed, AppointmentStatus::Done, $actor, $source, AuditAction::AppointmentCompleted,
                $outcome, after: ['status' => AppointmentStatus::Done->value]);

            return $appointment;
        });
    }
}
