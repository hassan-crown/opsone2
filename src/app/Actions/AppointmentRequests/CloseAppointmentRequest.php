<?php

namespace App\Actions\AppointmentRequests;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentRequestCloseReason;
use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Illuminate\Validation\ValidationException;

/**
 * Closes an open request without booking (P-54, ADR-0039): a reason from the closed list, a note required for «other».
 * Final; the customer may send a new request.
 */
final class CloseAppointmentRequest
{
    public function handle(User $actor, AppointmentRequest $request, AppointmentRequestCloseReason $reason, ?string $note, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): AppointmentRequest
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);
        if ($reason === AppointmentRequestCloseReason::Other && $note === null) {
            throw ValidationException::withMessages(['close_note' => __('appointment_requests.validation.note_required')]);
        }

        return LockingTransaction::run(function () use ($actor, $request, $reason, $note, $lockVersion, $source) {
            $request = AppointmentRequest::query()->lockForUpdate()->findOrFail($request->id);
            Access::authorize($actor, Permission::P54, $request);
            if ($lockVersion !== null && $lockVersion !== $request->lock_version) {
                throw new StaleRecord($request->lock_version);
            }
            if (! $request->status->isOpen()) {
                throw ValidationException::withMessages(['request' => __('appointment_requests.validation.not_open')]);
            }

            $before = ['status' => $request->status->value];
            $request->forceFill([
                'status' => AppointmentRequestStatus::Closed, 'close_reason' => $reason, 'close_note' => $note,
                'closed_by' => $actor->id, 'closed_at' => now(), 'lock_version' => $request->lock_version + 1,
            ])->save();
            Audit::record(AuditAction::AppointmentRequestClosed, $request, $actor, before: $before,
                after: ['status' => AppointmentRequestStatus::Closed->value, 'close_reason' => $reason->value],
                reason: $note, source: $source, label: $request->reference);

            return $request;
        });
    }
}
