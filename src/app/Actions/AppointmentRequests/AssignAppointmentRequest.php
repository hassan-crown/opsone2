<?php

namespace App\Actions\AppointmentRequests;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\AppointmentRequests\AppointmentRequests;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Illuminate\Validation\ValidationException;

/**
 * Hands an open request to the person who will call the customer (P-54 over the request, ADR-0039). The assignee must
 * cover the request's branch with their own P-54; their response time (settings `response_hours`) starts now and is
 * fixed on the request. Re-assigning restarts it and notifies the new assignee.
 */
final class AssignAppointmentRequest
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(User $actor, AppointmentRequest $request, int $assigneeId, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): AppointmentRequest
    {
        return LockingTransaction::run(function () use ($actor, $request, $assigneeId, $lockVersion, $source) {
            $request = AppointmentRequest::query()->lockForUpdate()->findOrFail($request->id);
            Access::authorize($actor, Permission::P54, $request);
            if ($lockVersion !== null && $lockVersion !== $request->lock_version) {
                throw new StaleRecord($request->lock_version);
            }
            if (! $request->status->isOpen()) {
                throw ValidationException::withMessages(['request' => __('appointment_requests.validation.not_open')]);
            }
            $assignee = User::query()->find($assigneeId);
            if ($assignee === null || ! AppointmentRequests::canWork($assignee, $request)) {
                throw ValidationException::withMessages(['assignee_id' => __('appointment_requests.validation.assignee')]);
            }

            $before = ['status' => $request->status->value, 'assignee_id' => $request->assignee_id];
            $hours = AppointmentRequestSettings::current()->response_hours;
            $request->forceFill([
                'status' => AppointmentRequestStatus::Assigned, 'assignee_id' => $assignee->id, 'assigned_by' => $actor->id,
                'assigned_at' => now(), 'due_at' => $hours === null ? null : now()->addHours($hours),
                'lock_version' => $request->lock_version + 1,
            ])->save();
            Audit::record(AuditAction::AppointmentRequestAssigned, $request, $actor, before: $before,
                after: ['status' => $request->status->value, 'assignee_id' => $assignee->id, 'due_at' => $request->due_at?->toIso8601String()],
                source: $source, label: $request->reference);

            if ($assignee->id !== $actor->id) {
                $this->notifier->send($assignee, 'appointment_request.assigned', [
                    'reference' => (string) $request->reference, 'name' => $request->name,
                    'kind' => __('appointments.kind.'.$request->kind, [], $assignee->locale ?? null),
                ], $request, "appointment_request:{$request->id}:assigned:{$request->lock_version}");
            }

            return $request;
        });
    }
}
