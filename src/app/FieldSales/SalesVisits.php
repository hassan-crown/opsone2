<?php

namespace App\FieldSales;

use App\Actions\StateMachines\M16Appointment\T05CompleteVisit;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentStatus;
use App\Files\AttachmentService;
use App\Installation\FieldAccess;
use App\Installation\FieldRefused;
use App\Models\Appointment;
use App\Models\FieldSales\SalesVisit;
use App\Models\FieldSales\SalesVisitPhoto;
use App\Models\Order;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The field rep's steps on a visit/measurement appointment (ADR-0041), each for a booked member holding P-54 over it
 * (FieldAccess) and written under the order row lock with its audit entry:
 *
 * start («وصلت») → notes and site photos (measurements, quote and links have their own existing paths) → finish,
 * which completes the appointment through M-16-T05 with the rep's outcome.
 */
final class SalesVisits
{
    public const PHOTO_PURPOSE = 'sales_visit_photo';

    public function __construct(
        private readonly AttachmentService $files,
        private readonly T05CompleteVisit $completeVisit,
    ) {}

    public function start(User $actor, Appointment $appointment): SalesVisit
    {
        return LockingTransaction::run(function () use ($actor, $appointment) {
            $order = Order::query()->lockForUpdate()->findOrFail($appointment->order_id);
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $locked->setRelation('order', $order);
            self::authorize($actor, $locked);
            if ($locked->status !== AppointmentStatus::Confirmed) {
                throw new FieldRefused('not_confirmed');
            }
            if (SalesVisit::query()->where('appointment_id', $locked->id)->exists()) {
                throw new FieldRefused('already_arrived');
            }
            $visit = SalesVisit::query()->create([
                'appointment_id' => $locked->id, 'order_id' => $order->id, 'rep_id' => $actor->id, 'arrived_at' => now(),
            ]);
            Audit::record(AuditAction::FieldVisitStarted, $order, $actor, after: ['appointment' => $locked->id, 'kind' => $locked->kind],
                branchIds: [$order->branch_id], label: $order->number);

            return $visit;
        });
    }

    public function note(User $actor, SalesVisit $visit, string $note, ?int $lockVersion = null): SalesVisit
    {
        $note = trim($note);
        if (mb_strlen($note) > 5000) {
            throw ValidationException::withMessages(['note' => __('field_sales.validation.note')]);
        }

        return LockingTransaction::run(function () use ($actor, $visit, $note, $lockVersion) {
            [$order, $locked] = $this->lockOpen($actor, $visit);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            $locked->forceFill(['note' => $note === '' ? null : $note, 'lock_version' => $locked->lock_version + 1])->save();
            // The text stays on the visit; the audit only says that it changed.
            Audit::record(AuditAction::FieldVisitNoted, $order, $actor, after: ['appointment' => $locked->appointment_id, 'length' => mb_strlen($note)],
                branchIds: [$order->branch_id], label: $order->number);

            return $locked;
        });
    }

    /**
     * A site photo (already compressed on the device). The device's upload id makes a retry return the stored photo.
     */
    public function addPhoto(User $actor, SalesVisit $visit, UploadedFile $file, string $clientUuid): SalesVisitPhoto
    {
        $existing = SalesVisitPhoto::query()->where('client_uuid', $clientUuid)->first();
        if ($existing !== null) {
            if ($existing->sales_visit_id !== $visit->id) {
                throw ValidationException::withMessages(['client_uuid' => __('installation.error.client_uuid')]);
            }

            return $existing;
        }

        return LockingTransaction::run(function () use ($actor, $visit, $file, $clientUuid) {
            [$order, $locked] = $this->lockOpen($actor, $visit);
            // The page and the service worker may send the same queued photo at once: the visit row lock orders
            // them, and the second one returns what the first stored.
            $stored = SalesVisitPhoto::query()->where('client_uuid', $clientUuid)->where('sales_visit_id', $locked->id)->first();
            if ($stored !== null) {
                return $stored;
            }
            if (SalesVisitPhoto::query()->where('sales_visit_id', $locked->id)->count() >= SalesVisit::MAX_PHOTOS) {
                throw new FieldRefused('too_many_photos');
            }
            $attachment = $this->files->store($actor, $locked, $file, self::PHOTO_PURPOSE);
            $photo = SalesVisitPhoto::query()->create([
                'sales_visit_id' => $locked->id, 'attachment_id' => $attachment->id, 'client_uuid' => $clientUuid, 'uploaded_by' => $actor->id,
            ]);
            Audit::record(AuditAction::FieldVisitPhotoAdded, $order, $actor, after: ['appointment' => $locked->appointment_id, 'attachment' => $attachment->uuid],
                branchIds: [$order->branch_id], label: $order->number);

            return $photo;
        });
    }

    /**
     * «انتهت الزيارة»: the outcome completes the appointment (M-16-T05, which checks its own machine again).
     */
    public function finish(User $actor, SalesVisit $visit, string $outcome): SalesVisit
    {
        $outcome = trim($outcome);
        if (mb_strlen($outcome) < 2 || mb_strlen($outcome) > 1000) {
            throw ValidationException::withMessages(['outcome' => __('field_sales.validation.outcome')]);
        }

        return LockingTransaction::run(function () use ($actor, $visit, $outcome) {
            [$order, $locked] = $this->lockOpen($actor, $visit);
            $locked->forceFill(['finished_at' => now(), 'outcome' => $outcome, 'lock_version' => $locked->lock_version + 1])->save();
            Audit::record(AuditAction::FieldVisitFinished, $order, $actor, after: [
                'appointment' => $locked->appointment_id, 'minutes' => (int) round($locked->arrived_at->diffInSeconds($locked->finished_at, true) / 60),
            ], branchIds: [$order->branch_id], label: $order->number);
            $this->completeVisit->handle($actor, $locked->appointment, $outcome);

            return $locked->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(User $actor, Appointment $appointment): void
    {
        if (! FieldAccess::isSalesKind($appointment->kind)) {
            throw new AuthorizationException(__('access.denied.no_grant'));
        }
        FieldAccess::authorize($actor, $appointment);
    }

    /**
     * Locks the order then the visit, checks the actor is booked on it and that it is running.
     *
     * @return array{0: Order, 1: SalesVisit}
     */
    private function lockOpen(User $actor, SalesVisit $visit): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($visit->order_id);
        $locked = SalesVisit::query()->lockForUpdate()->findOrFail($visit->id);
        $appointment = Appointment::query()->findOrFail($locked->appointment_id);
        $appointment->setRelation('order', $order);
        $locked->setRelation('appointment', $appointment);
        $locked->setRelation('order', $order);
        self::authorize($actor, $appointment);
        if ($locked->isFinished()) {
            throw new FieldRefused('already_finished');
        }

        return [$order, $locked];
    }
}
