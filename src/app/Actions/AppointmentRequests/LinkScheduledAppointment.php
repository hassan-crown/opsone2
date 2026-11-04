<?php

namespace App\Actions\AppointmentRequests;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * ApptSchedule opened from a request (ADR-0039): inside the booking's own transaction the new appointment is linked
 * and the request marked scheduled — a refused booking leaves the request open. The order must match the request
 * (its order, else an order of its customer; a request from an interest takes the order staff chose). No customer
 * approval: the appointment's own confirmation/booking message goes out through the outbox as for any appointment.
 */
final class LinkScheduledAppointment
{
    public function handle(User $actor, int $requestId, Appointment $appointment, string $source = TransitionRecorder::SOURCE_WEB): AppointmentRequest
    {
        $request = AppointmentRequest::query()->lockForUpdate()->find($requestId);
        if ($request === null || ! Access::can($actor, Permission::P54, $request)) {
            throw (new ModelNotFoundException)->setModel(AppointmentRequest::class, [$requestId]);
        }
        if (! $request->status->isOpen()) {
            throw ValidationException::withMessages(['appointment_request_id' => __('appointment_requests.validation.not_open')]);
        }
        $order = $appointment->order;
        $mismatch = ($request->order_id !== null && $request->order_id !== $order->id)
            || ($request->order_id === null && $request->customer_id !== null && $request->customer_id !== $order->customer_id);
        if ($mismatch) {
            throw ValidationException::withMessages(['order_id' => __('appointment_requests.validation.order_mismatch')]);
        }

        $before = ['status' => $request->status->value];
        $request->forceFill([
            'status' => AppointmentRequestStatus::Scheduled, 'appointment_id' => $appointment->id,
            'order_id' => $order->id, 'customer_id' => $order->customer_id,
            'scheduled_by' => $actor->id, 'scheduled_at' => now(), 'lock_version' => $request->lock_version + 1,
        ])->save();
        Audit::record(AuditAction::AppointmentRequestScheduled, $request, $actor, before: $before, after: [
            'status' => AppointmentRequestStatus::Scheduled->value, 'appointment_id' => $appointment->id, 'order_id' => $order->id,
        ], source: $source, label: $request->reference);

        return $request;
    }
}
