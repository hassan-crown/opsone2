<?php

namespace App\Portal;

use App\Access\TransitionRecorder;
use App\Agreements\CustomerActor;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentStatus;
use App\Enums\StateMachine;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentBooking;
use App\Workflow\AppointmentLog;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The customer's answer to an appointment sent to them (P-70, WEB-11, M-16):
 * - confirm (T02 from the account): the same member-locked conflict check as staff; a customer can never confirm over
 *   another booking (no conflict reason from the customer), so a clash is refused and staff propose another time;
 * - ask to change it: the original stays booked (no automatic cancellation); the request (note and preferred time,
 *   free text) goes to the staff who answer with a proposal (T03) or keep the time. Without published facility slots
 *   (none in v1) the customer never books a time directly.
 */
final class CustomerAppointments
{
    public const METHOD = 'customer_account';

    public const NOTIFY_CHANGE = 'portal.appointment_change_requested';

    public function __construct(
        private readonly AppointmentBooking $booking,
        private readonly AppointmentLog $log,
        private readonly TransitionRecorder $transitions,
        private readonly Notifier $notifier,
    ) {}

    public function confirm(CustomerActor $customer, Appointment $appointment): Appointment
    {
        return LockingTransaction::run(function () use ($customer, $appointment) {
            $this->booking->lockMembers($appointment->memberIds());
            $appointment = $this->log->lock($appointment, null);
            self::ensureOwn($customer, $appointment);
            $from = $appointment->status;
            if ($from !== AppointmentStatus::AwaitingConfirmation) {
                Transition::refuse(StateMachine::M16, 'T02', 'not_from_'.$from->value);
            }
            $members = $appointment->memberIds();
            if ($this->booking->conflicts($members, $appointment->starts_at, $appointment->ends_at, $appointment->id)->isNotEmpty()) {
                throw ValidationException::withMessages(['appointment' => __('portal.appointments.unavailable')]);
            }

            $appointment->forceFill([
                'status' => AppointmentStatus::Confirmed, 'confirmation_method' => self::METHOD, 'confirmed_by' => null,
                'confirmed_at' => now(), 'conflict_reason' => null, 'conflicted' => false, 'customer_request' => null,
                'lock_version' => $appointment->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M16, 'T02', $appointment, $from, AppointmentStatus::Confirmed, null, PortalSession::SOURCE, null,
                ['order_id' => $appointment->order_id, 'method' => self::METHOD]);
            Audit::record(AuditAction::AppointmentConfirmed, $appointment, null, after: AppointmentLog::snapshot($appointment),
                source: PortalSession::SOURCE, branchIds: [$appointment->order->branch_id], label: $appointment->order->number);
            $this->booking->notify($appointment, 'appointment.confirmed', $members);

            return $appointment;
        });
    }

    public function requestChange(CustomerActor $customer, Appointment $appointment, string $note, ?string $preferred): Appointment
    {
        $note = WorkGuard::reason($note, 'note');
        $preferred = $preferred === null ? null : trim($preferred);
        if ($preferred !== null && mb_strlen($preferred) > 200) {
            throw ValidationException::withMessages(['preferred' => __('portal.validation.preferred')]);
        }

        return LockingTransaction::run(function () use ($customer, $appointment, $note, $preferred) {
            $appointment = $this->log->lock($appointment, null);
            self::ensureOwn($customer, $appointment);
            if (! in_array($appointment->status, [AppointmentStatus::AwaitingConfirmation, AppointmentStatus::Confirmed], true)) {
                Transition::refuse(StateMachine::M16, 'T07', 'not_from_'.$appointment->status->value);
            }
            if ($appointment->customer_request !== null) {
                Transition::refuse(StateMachine::M16, 'T07', 'change_pending');
            }

            $request = ['note' => $note, 'preferred' => $preferred === '' ? null : $preferred, 'requested_at' => now()->toIso8601String()];
            $appointment->forceFill(['customer_request' => $request, 'lock_version' => $appointment->lock_version + 1])->save();
            Audit::record(AuditAction::AppointmentCustomerChangeRequested, $appointment, null, after: ['customer_request' => $request],
                reason: $note, source: PortalSession::SOURCE, branchIds: [$appointment->order->branch_id], label: $appointment->order->number);

            $staff = array_filter([$appointment->created_by, $appointment->order->assignee_id]);
            foreach (User::query()->whereIn('id', array_unique($staff))->get() as $user) {
                $this->notifier->send($user, self::NOTIFY_CHANGE, ['order' => $appointment->order->number],
                    $appointment, "appointment:{$appointment->id}:customer_change:{$appointment->lock_version}:{$user->id}");
            }

            return $appointment;
        });
    }

    private static function ensureOwn(CustomerActor $customer, Appointment $appointment): void
    {
        if ($appointment->order->customer_id !== $customer->customerId) {
            throw new AuthorizationException;
        }
    }
}
