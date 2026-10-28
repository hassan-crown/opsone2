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
use Carbon\CarbonImmutable;

/**
 * M-16-T04 (P-54): approve the pending alternative. The conflict check runs again atomically, under the same policy as
 * a confirmation (the appointment being moved never conflicts with itself); a refused alternative leaves the original
 * booked. The previous time and the reason are kept; other appointments and manufacturing are not recomputed
 * (SHR-04); the members are notified.
 */
final class T04ApproveChange
{
    public function __construct(private readonly AppointmentBooking $booking, private readonly AppointmentLog $log) {}

    public function handle(User $actor, Appointment $appointment, ?string $conflictReason = null, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Appointment
    {
        return LockingTransaction::run(function () use ($actor, $appointment, $conflictReason, $lockVersion, $source) {
            $this->booking->lockMembers($appointment->memberIds());
            $appointment = $this->log->lock($appointment, $lockVersion);
            Access::authorize($actor, Permission::P54, $appointment);
            Transition::check(StateMachine::M16, AppointmentStatus::transitions(), $appointment->status, 'T04');
            $change = $appointment->pending_change ?? Transition::refuse(StateMachine::M16, 'T04', 'no_change_pending');

            $members = $appointment->memberIds();
            $start = CarbonImmutable::parse($change['starts_at']);
            $end = CarbonImmutable::parse($change['ends_at']);
            $conflicts = $this->booking->conflicts($members, $start, $end, $appointment->id);
            $reason = $this->booking->decide($conflicts, $conflictReason, 'T04');

            $before = AppointmentLog::snapshot($appointment);
            $oldStart = $appointment->starts_at;
            $oldEnd = $appointment->ends_at;
            $appointment->forceFill([
                'starts_at' => $start,
                'ends_at' => $end,
                'pending_change' => null,
                'conflicted' => $conflicts->isNotEmpty(),
                'conflict_reason' => $reason ?? $appointment->conflict_reason,
            ]);
            $this->log->record($appointment, 'T04', AppointmentStatus::Confirmed, AppointmentStatus::Confirmed, $actor, $source, AuditAction::AppointmentChanged,
                $change['reason'], ['previous' => $before, 'conflict_reason' => $reason], $before, AppointmentLog::snapshot($appointment));

            $this->booking->refreshMarkers($members, $oldStart, $oldEnd);
            $this->booking->refreshMarkers($members, $start, $end);
            $this->booking->notify($appointment, 'appointment.changed', $members);
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
