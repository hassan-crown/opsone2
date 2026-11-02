<?php

namespace App\Installation;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M06Stage\T04CompleteStage;
use App\Actions\StateMachines\M07Task\T02StartTask;
use App\Actions\StateMachines\M07Task\T05CompleteTask;
use App\Actions\StateMachines\M16Appointment\T05CompleteVisit;
use App\Agreements\Signing\ESignature;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentStatus;
use App\Enums\TaskStatus;
use App\Files\AttachmentService;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\Installation\InstallationPhoto;
use App\Models\Installation\InstallationRemark;
use App\Models\Installation\InstallationVisit;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Outbox;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use App\Workflow\RequirementsOpen;
use App\Workflow\StageEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The technician's steps on one installation appointment (ADR-0036), each authorized for a booked member holding
 * P-24 (FieldAccess) and written under the order row lock with its audit entry:
 *
 * arrive → before/after photos → remarks (InstallationRemarks) → customer acceptance signature → finish.
 *
 * Finishing moves the work only through the engine's own Actions: the appointment's outcome (M-16-T05), the
 * installation task (M-07-T02/T05 → M-27-T03 via the StageEngine) and, when nothing else is open and the
 * technician holds P-25 over the stage, the stage itself (M-06-T04 → next stage or M-04-T03, which raises the
 * existing «order completed» WhatsApp event). Nothing is bypassed: a step the engine refuses refuses the finish.
 */
final class FieldVisits
{
    public const MIN_PHOTOS_PER_PHASE = 1;

    public const MAX_PHOTOS_PER_PHASE = 12;

    public const SIGNATURE_PURPOSE = 'installation_signature';

    public function __construct(
        private readonly AttachmentService $files,
        private readonly T05CompleteVisit $completeVisit,
        private readonly T02StartTask $startTask,
        private readonly T05CompleteTask $completeTask,
        private readonly T04CompleteStage $completeStage,
        private readonly StageEngine $engine,
        private readonly Outbox $outbox,
        private readonly SmsChannel $sms,
    ) {}

    /**
     * «وصلت»: the arrival time and, when the device shared it, one location reading. Starts the installation task
     * when it is waiting for this technician.
     */
    public function arrive(User $actor, Appointment $appointment, ?float $latitude = null, ?float $longitude = null, ?int $accuracy = null): InstallationVisit
    {
        return LockingTransaction::run(function () use ($actor, $appointment, $latitude, $longitude, $accuracy) {
            $order = Order::query()->lockForUpdate()->findOrFail($appointment->order_id);
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $locked->setRelation('order', $order);
            FieldAccess::authorize($actor, $locked);
            if ($locked->status !== AppointmentStatus::Confirmed) {
                throw new FieldRefused('not_confirmed');
            }
            if (InstallationVisit::query()->where('appointment_id', $locked->id)->exists()) {
                throw new FieldRefused('already_arrived');
            }
            $located = $latitude !== null && $longitude !== null;

            $visit = InstallationVisit::query()->create([
                'appointment_id' => $locked->id, 'order_id' => $order->id, 'status' => InstallationStatus::InProgress,
                'arrived_at' => now(), 'arrived_by' => $actor->id,
                'arrival_latitude' => $located ? round($latitude, 7) : null, 'arrival_longitude' => $located ? round($longitude, 7) : null,
                'arrival_accuracy_m' => $located && $accuracy !== null ? max(0, min($accuracy, 100000)) : null,
            ]);
            $visit->setRelation('appointment', $locked);
            // The location itself stays on the visit; the audit only says that one was taken.
            Audit::record(AuditAction::InstallationArrived, $order, $actor, after: ['appointment' => $locked->id, 'located' => $located],
                branchIds: [$order->branch_id], label: $order->number);

            $task = self::taskOf($locked);
            if ($task !== null && $task->status === TaskStatus::Assigned && Access::can($actor, Permission::P24, $task)) {
                $this->startTask->handle($actor, $task);
            }

            return $visit;
        });
    }

    /**
     * A before/after photo (already compressed on the device). The device's upload id makes a retry after a lost
     * response return the photo it already stored.
     */
    public function addPhoto(User $actor, InstallationVisit $visit, string $phase, UploadedFile $file, string $clientUuid): InstallationPhoto
    {
        if (! in_array($phase, InstallationPhoto::PHASES, true)) {
            throw ValidationException::withMessages(['phase' => __('installation.error.phase')]);
        }
        $existing = InstallationPhoto::query()->where('client_uuid', $clientUuid)->first();
        if ($existing !== null) {
            if ($existing->installation_visit_id !== $visit->id || $existing->phase !== $phase) {
                throw ValidationException::withMessages(['client_uuid' => __('installation.error.client_uuid')]);
            }

            return $existing;
        }

        return LockingTransaction::run(function () use ($actor, $visit, $phase, $file, $clientUuid) {
            [$order, $locked] = $this->lockOpen($actor, $visit);
            if (InstallationPhoto::query()->where('installation_visit_id', $locked->id)->where('phase', $phase)->count() >= self::MAX_PHOTOS_PER_PHASE) {
                throw new FieldRefused('too_many_photos');
            }
            if ($phase === InstallationPhoto::BEFORE && InstallationPhoto::query()->where('installation_visit_id', $locked->id)->where('phase', InstallationPhoto::AFTER)->exists()) {
                // «قبل» documents the site as found; once «بعد» exists the order of evidence would be misleading.
                throw new FieldRefused('before_after_after');
            }

            $attachment = $this->files->store($actor, $locked, $file, 'installation_'.$phase);
            $photo = InstallationPhoto::query()->create([
                'installation_visit_id' => $locked->id, 'attachment_id' => $attachment->id, 'phase' => $phase,
                'client_uuid' => $clientUuid, 'uploaded_by' => $actor->id,
            ]);
            Audit::record(AuditAction::InstallationPhotoAdded, $order, $actor, after: ['appointment' => $locked->appointment_id, 'phase' => $phase, 'attachment' => $attachment->uuid],
                branchIds: [$order->branch_id], label: $order->number);

            return $photo;
        });
    }

    /**
     * The customer accepts the work on the technician's phone: typed name, drawn signature, explicit consent.
     *
     * @param  array{signer_name: string, signature: string, consent: bool}  $input
     */
    public function signOnDevice(User $actor, InstallationVisit $visit, array $input, ?string $ip, ?string $userAgent): InstallationVisit
    {
        [$name, $png] = self::signatureInput($input);

        return LockingTransaction::run(function () use ($actor, $visit, $name, $png, $ip, $userAgent) {
            [$order, $locked] = $this->lockOpen($actor, $visit);

            return $this->storeSignature($locked, $order, InstallationVisit::SIGNED_ON_DEVICE, $name, $png, $ip, $userAgent, $actor);
        });
    }

    /**
     * Sends the customer a link to sign the acceptance in their own workspace (they sign in there as usual).
     */
    public function requestPortalSignature(User $actor, InstallationVisit $visit): InstallationVisit
    {
        return LockingTransaction::run(function () use ($actor, $visit) {
            [$order, $locked] = $this->lockOpen($actor, $visit);
            $customer = Customer::query()->findOrFail($order->customer_id);
            if ($locked->isSigned()) {
                throw new FieldRefused('already_signed');
            }
            if (! $customer->portal_login_enabled) {
                throw new FieldRefused('portal_disabled');
            }
            $email = (string) $customer->email;
            $bySms = $this->sms->isConfigured();
            if (! $bySms && $email === '') {
                throw new FieldRefused('no_recipient');
            }

            $locked->forceFill(['signature_requested_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
            $facility = Facility::current();
            $locale = $customer->portal_locale ?? ($facility === null ? 'ar' : $facility->default_locale);
            $url = route('customer.installations.show', ['visit' => $locked->id]);
            $params = [
                'facility' => $facility === null ? (string) config('app.name') : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar),
                'order' => $order->number, 'link' => $url,
            ];
            $key = "installation-sign:{$locked->id}:{$locked->signature_requested_at?->timestamp}";
            if ($bySms) {
                $this->outbox->enqueueSms($customer->mobile, __('installation.sign_link.sms', $params, $locale), $key.':sms');
            }
            if ($email !== '') {
                $this->outbox->enqueueMail($email, __('installation.sign_link.mail_subject', $params, $locale), [__('installation.sign_link.mail_line', $params, $locale)],
                    ['label' => __('installation.sign_link.mail_action', [], $locale), 'url' => $url], $locale, $key.':mail');
            }
            Audit::record(AuditAction::InstallationSignatureRequested, $order, $actor, after: ['appointment' => $locked->appointment_id, 'channel' => $bySms ? 'sms' : 'email'],
                branchIds: [$order->branch_id], label: $order->number);

            return $locked;
        });
    }

    /**
     * The customer signs from their workspace (already signed in; the visit was scoped to them by the caller).
     *
     * @param  array{signer_name: string, signature: string, consent: bool}  $input
     */
    public function signFromPortal(Customer $customer, InstallationVisit $visit, array $input, ?string $ip, ?string $userAgent): InstallationVisit
    {
        [$name, $png] = self::signatureInput($input);

        return LockingTransaction::run(function () use ($customer, $visit, $name, $png, $ip, $userAgent) {
            $order = Order::query()->lockForUpdate()->findOrFail($visit->order_id);
            if ($order->customer_id !== $customer->id) {
                abort(404);
            }
            $locked = InstallationVisit::query()->with('appointment')->lockForUpdate()->findOrFail($visit->id);
            if (! self::portalSignable($locked)) {
                throw new FieldRefused($locked->isSigned() ? 'already_signed' : 'not_signable');
            }

            return $this->storeSignature($locked, $order, InstallationVisit::SIGNED_IN_PORTAL, $name, $png, $ip, $userAgent, null);
        });
    }

    /** Can the customer sign this visit's acceptance in their workspace now? */
    public static function portalSignable(InstallationVisit $visit): bool
    {
        return $visit->signature_requested_at !== null && ! $visit->isSigned() && ! $visit->isFinished();
    }

    /**
     * «انتهى»: at least one before and one after photo and the customer's signature, then the engine path above.
     *
     * @return array{visit: InstallationVisit, task_completed: bool, stage_completed: bool}
     */
    public function finish(User $actor, InstallationVisit $visit, ?string $note = null, ?int $lockVersion = null): array
    {
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => __('installation.error.note')]);
        }

        return LockingTransaction::run(function () use ($actor, $visit, $note, $lockVersion) {
            [$order, $locked] = $this->lockOpen($actor, $visit);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            foreach (InstallationPhoto::PHASES as $phase) {
                if (InstallationPhoto::query()->where('installation_visit_id', $locked->id)->where('phase', $phase)->count() < self::MIN_PHOTOS_PER_PHASE) {
                    throw new FieldRefused('photos_'.$phase.'_missing');
                }
            }
            if (! $locked->isSigned()) {
                throw new FieldRefused('signature_missing');
            }

            $remarks = InstallationRemark::query()->where('installation_visit_id', $locked->id)->where('blocking', true)->exists();
            $locked->forceFill([
                'status' => $remarks ? InstallationStatus::FinishedWithRemarks : InstallationStatus::Finished,
                'finished_at' => now(), 'finished_by' => $actor->id, 'finish_note' => $note === '' ? null : $note,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            Audit::record(AuditAction::InstallationFinished, $order, $actor, after: [
                'appointment' => $locked->appointment_id, 'status' => $locked->status->value, 'minutes' => $locked->durationMinutes(),
            ], branchIds: [$order->branch_id], label: $order->number);

            // The engine's own Actions, as the technician; each authorizes and checks its machine again.
            $appointment = $locked->appointment;
            $this->completeVisit->handle($actor, $appointment, $note === null || $note === '' ? __('installation.outcome.finished') : $note);

            $taskDone = false;
            $task = self::taskOf($appointment);
            if ($task !== null && Access::can($actor, Permission::P24, $task)) {
                if ($task->status === TaskStatus::Assigned) {
                    $task = $this->startTask->handle($actor, $task);
                }
                if ($task->status === TaskStatus::InProgress) {
                    $this->completeTask->handle($actor, $task, $note === '' ? null : $note);
                    $taskDone = true;
                }
            }

            return ['visit' => $locked->refresh(), 'task_completed' => $taskDone, 'stage_completed' => $taskDone && $this->completeStageIfReady($actor, $order)];
        });
    }

    /**
     * M-06-T04 once nothing required is open, only for a technician who holds P-25 over the stage (Q-30 a);
     * otherwise the stage waits for its manager as usual.
     */
    private function completeStageIfReady(User $actor, Order $order): bool
    {
        $stage = $this->engine->activeStage($order->refresh());
        if ($stage === null || ! Access::can($actor, Permission::P25, $stage)) {
            return false;
        }
        try {
            $this->completeStage->handle($actor, $stage);
        } catch (RequirementsOpen) {
            return false; // refused before any write: other required work of the stage is still open
        }

        return true;
    }

    /**
     * The installation task the appointment serves: its own task, else the open task of the order's
     * `installation` action.
     */
    public static function taskOf(Appointment $appointment): ?Task
    {
        $query = Task::query()->with(['order', 'action']);
        if ($appointment->task_id !== null) {
            return $query->find($appointment->task_id);
        }

        return $query->where('order_id', $appointment->order_id)
            ->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, TaskStatus::open()))
            ->whereHas('action', fn ($q) => $q->where('key', 'installation'))
            ->orderBy('id')->first();
    }

    /**
     * Locks the order then the visit, checks the actor is booked on it and that it is running.
     *
     * @return array{0: Order, 1: InstallationVisit}
     */
    private function lockOpen(User $actor, InstallationVisit $visit): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($visit->order_id);
        $locked = InstallationVisit::query()->lockForUpdate()->findOrFail($visit->id);
        $appointment = Appointment::query()->findOrFail($locked->appointment_id);
        $appointment->setRelation('order', $order);
        $locked->setRelation('appointment', $appointment);
        $locked->setRelation('order', $order);
        FieldAccess::authorize($actor, $appointment);
        if ($locked->isFinished()) {
            throw new FieldRefused('already_finished');
        }

        return [$order, $locked];
    }

    private function storeSignature(InstallationVisit $visit, Order $order, string $method, string $name, string $png, ?string $ip, ?string $userAgent, ?User $actor): InstallationVisit
    {
        if ($visit->isSigned()) {
            throw new FieldRefused('already_signed');
        }
        $image = $this->files->storeGenerated($visit, $png, 'acceptance-'.$order->number, 'png', 'image/png', self::SIGNATURE_PURPOSE, $actor);
        $visit->forceFill([
            'signature_method' => $method, 'signer_name' => $name, 'signed_at' => now(),
            'signature_attachment_id' => $image->id, 'signature_sha256' => $image->sha256,
            'signature_ip' => $ip, 'signature_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'lock_version' => $visit->lock_version + 1,
        ])->save();
        Audit::record(AuditAction::InstallationSigned, $order, $actor, after: [
            'appointment' => $visit->appointment_id, 'method' => $method, 'signature_sha256' => $image->sha256,
        ], source: $actor === null ? 'customer' : TransitionRecorder::SOURCE_WEB, branchIds: [$order->branch_id], label: $order->number);

        return $visit;
    }

    /**
     * @param  array{signer_name: string, signature: string, consent: bool}  $input
     * @return array{0: string, 1: string}
     */
    private static function signatureInput(array $input): array
    {
        if (! $input['consent']) {
            throw ValidationException::withMessages(['consent' => __('installation.error.consent')]);
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $input['signer_name']));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['signer_name' => __('esign.error.name')]);
        }

        return [$name, ESignature::signatureImage($input['signature'])];
    }
}
