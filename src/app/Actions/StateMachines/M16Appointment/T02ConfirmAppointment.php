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
use Illuminate\Validation\ValidationException;

/**
 * M-16-T02 (P-54, staff recording the customer's confirmation): atomic conflict check and booking. On overlap the
 * confirmation is refused, unless the facility allows conflicts with a reason and one is given: then it is kept with
 * the confirmation, every overlapping confirmed appointment is marked «متعارض» and the members are notified.
 */
final class T02ConfirmAppointment
{
    public function __construct(private readonly AppointmentBooking $booking, private readonly AppointmentLog $log) {}

    public function handle(User $actor, Appointment $appointment, string $method, ?string $conflictReason = null, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Appointment
    {
        if (! in_array($method, Appointment::CONFIRMATION_METHODS, true)) {
            throw ValidationException::withMessages(['confirmation_method' => __('workflow.validation.confirmation_method')]);
        }

        return LockingTransaction::run(function () use ($actor, $appointment, $method, $conflictReason, $lockVersion, $source) {
            // Members first: the booking lock every confirmation of these members queues on.
            $this->booking->lockMembers($appointment->memberIds());
            $appointment = $this->log->lock($appointment, $lockVersion);
            Access::authorize($actor, Permission::P54, $appointment);
            $from = $appointment->status;
            Transition::check(StateMachine::M16, AppointmentStatus::transitions(), $from, 'T02');

            $members = $appointment->memberIds();
            $conflicts = $this->booking->conflicts($members, $appointment->starts_at, $appointment->ends_at, $appointment->id);
            $reason = $this->booking->decide($conflicts, $conflictReason, 'T02');

            $appointment->forceFill([
                'status' => AppointmentStatus::Confirmed,
                'confirmation_method' => $method,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'conflict_reason' => $reason,
                'conflicted' => $conflicts->isNotEmpty(),
            ]);
            $this->log->record($appointment, 'T02', $from, AppointmentStatus::Confirmed, $actor, $source, AuditAction::AppointmentConfirmed, $reason,
                ['method' => $method, 'conflicts' => $conflicts->pluck('id')->all()], after: AppointmentLog::snapshot($appointment));
            $this->booking->refreshMarkers($members, $appointment->starts_at, $appointment->ends_at);

            $this->booking->notify($appointment, 'appointment.confirmed', $members);
            if ($conflicts->isNotEmpty()) {
                // Members of a clashing appointment hear about their own booking: another order may be out of their scope.
                $this->booking->notify($appointment, 'appointment.conflict', $members);
                foreach ($conflicts as $clash) {
                    $this->booking->notify($clash, 'appointment.conflict', array_values(array_diff($clash->memberIds(), $members)));
                }
            }

            return $appointment;
        });
    }
}
