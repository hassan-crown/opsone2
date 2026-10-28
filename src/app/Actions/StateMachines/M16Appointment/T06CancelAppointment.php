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
use App\Workflow\AppointmentBooking;
use App\Workflow\AppointmentLog;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;

/**
 * M-16-T06 (P-54): cancel with a reason, releasing any booked time and recomputing the conflict markers of the
 * appointments it overlapped. The order and its money are untouched.
 */
final class T06CancelAppointment
{
    public function __construct(private readonly AppointmentBooking $booking, private readonly AppointmentLog $log) {}

    public function handle(User $actor, Appointment $appointment, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Appointment
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $appointment, $reason, $lockVersion, $source) {
            $this->booking->lockMembers($appointment->memberIds());
            $appointment = $this->log->lock($appointment, $lockVersion);
            Access::authorize($actor, Permission::P54, $appointment);
            $from = $appointment->status;
            Transition::check(StateMachine::M16, AppointmentStatus::transitions(), $from, 'T06');

            $appointment->forceFill(['status' => AppointmentStatus::Cancelled, 'cancel_reason' => $reason, 'conflicted' => false, 'pending_change' => null]);
            $this->log->record($appointment, 'T06', $from, AppointmentStatus::Cancelled, $actor, $source, AuditAction::AppointmentCancelled, $reason,
                before: ['status' => $from->value], after: ['status' => AppointmentStatus::Cancelled->value]);

            $members = $appointment->memberIds();
            if ($from === AppointmentStatus::Confirmed) {
                $this->booking->refreshMarkers($members, $appointment->starts_at, $appointment->ends_at);
            }
            $this->booking->notify($appointment, 'appointment.cancelled', $members);

            return $appointment;
        });
    }
}
