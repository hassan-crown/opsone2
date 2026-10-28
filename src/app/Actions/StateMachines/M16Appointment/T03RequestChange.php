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
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * M-16-T03 (P-54): propose another time for a confirmed appointment. The original stays booked until the alternative
 * is approved (T04); an overlap is only a warning here. A second proposal is refused while one is pending.
 */
final class T03RequestChange
{
    public function __construct(private readonly AppointmentBooking $booking, private readonly AppointmentLog $log) {}

    /**
     * @return array{appointment: Appointment, conflicts: list<int>}
     */
    public function handle(User $actor, Appointment $appointment, string $startsAt, string $endsAt, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): array
    {
        $reason = WorkGuard::reason($reason);
        $start = CarbonImmutable::parse($startsAt)->utc();
        $end = CarbonImmutable::parse($endsAt)->utc();
        if ($end->lessThanOrEqualTo($start) || $end->diffInHours($start, true) > 72) {
            throw ValidationException::withMessages(['ends_at' => __('workflow.validation.duration_too_long')]);
        }

        return LockingTransaction::run(function () use ($actor, $appointment, $start, $end, $reason, $lockVersion, $source) {
            $appointment = $this->log->lock($appointment, $lockVersion);
            Access::authorize($actor, Permission::P54, $appointment);
            Transition::check(StateMachine::M16, AppointmentStatus::transitions(), $appointment->status, 'T03');
            if ($appointment->pending_change !== null) {
                Transition::refuse(StateMachine::M16, 'T03', 'change_pending');
            }

            $appointment->pending_change = [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'reason' => $reason,
                'requested_by' => $actor->id,
                'requested_at' => now()->toIso8601String(),
            ];
            $conflicts = array_values($this->booking->conflicts($appointment->memberIds(), $start, $end, $appointment->id)->pluck('id')->map(intval(...))->all());
            $this->log->record($appointment, 'T03', AppointmentStatus::Confirmed, AppointmentStatus::Confirmed, $actor, $source, AuditAction::AppointmentChangeRequested,
                $reason, ['change' => $appointment->pending_change, 'conflicts' => $conflicts], after: ['pending_change' => $appointment->pending_change]);

            return ['appointment' => $appointment, 'conflicts' => $conflicts];
        });
    }

    /**
     * Drops a pending alternative; the original appointment is kept (no state change, audited).
     */
    public function discard(User $actor, Appointment $appointment, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Appointment
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $appointment, $reason, $lockVersion, $source) {
            $appointment = $this->log->lock($appointment, $lockVersion);
            Access::authorize($actor, Permission::P54, $appointment);
            if ($appointment->pending_change === null) {
                Transition::refuse(StateMachine::M16, 'T04', 'no_change_pending');
            }

            $change = $appointment->pending_change;
            $appointment->pending_change = null;
            $this->log->record($appointment, null, $appointment->status, $appointment->status, $actor, $source, AuditAction::AppointmentChangeDiscarded,
                $reason, before: ['pending_change' => $change]);

            return $appointment;
        });
    }
}
