<?php

namespace App\FieldSales;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\FieldSales\FieldAssignment;
use App\Models\Interest;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The supervisor hands a lead or a customer appointment request to a field sales rep (ADR-0041, P-105 over the
 * record's branch). The rep must cover that branch; the record's assignee becomes the rep (that is what their
 * `assigned` grants follow), an open FieldAssignment is the rep's task with the response time of the settings in
 * force, and the rep is notified. Re-assigning ends the previous assignment as `reassigned`.
 */
final class FieldDispatch
{
    public function __construct(private readonly Notifier $notifier) {}

    public static function canDispatch(User $user): bool
    {
        return FieldSalesConfig::enabled() && Access::can($user, Permission::P105);
    }

    /**
     * Assignments the dispatcher sees: P-105 over their branch.
     *
     * @return Builder<FieldAssignment>
     */
    public static function visible(User $user): Builder
    {
        return ScopedQuery::apply(FieldAssignment::query(), $user, [Permission::P105], 'branch_id', null, null);
    }

    public function assignLead(User $actor, Interest $interest, int $repId, ?string $note = null, string $source = TransitionRecorder::SOURCE_WEB): FieldAssignment
    {
        return LockingTransaction::run(function () use ($actor, $interest, $repId, $note, $source) {
            $locked = Interest::query()->lockForUpdate()->findOrFail($interest->id);
            Access::authorize($actor, Permission::P105, $locked);
            self::ensureEnabled();
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['interest' => __('field_sales.validation.lead_closed')]);
            }
            $rep = self::rep($repId, $locked->branch_id);

            $before = ['assignee_id' => $locked->assignee_id];
            $locked->forceFill(['assignee_id' => $rep->id])->save();
            $assignment = $this->open(FieldAssignment::INTEREST, $locked->id, $locked->branch_id, $rep, $actor, $note);
            Audit::record(AuditAction::FieldLeadAssigned, $locked, $actor, before: $before,
                after: ['assignee_id' => $rep->id, 'due_at' => $assignment->due_at?->toIso8601String()], source: $source, label: $locked->reference);

            if ($rep->id !== $actor->id) {
                $this->notifier->send($rep, 'field_sales.lead_assigned', ['reference' => (string) $locked->reference, 'name' => $locked->name],
                    $locked, "field_assignment:{$assignment->id}:assigned");
            }

            return $assignment;
        });
    }

    public function assignRequest(User $actor, AppointmentRequest $request, int $repId, ?int $lockVersion = null, ?string $note = null, string $source = TransitionRecorder::SOURCE_WEB): FieldAssignment
    {
        return LockingTransaction::run(function () use ($actor, $request, $repId, $lockVersion, $note, $source) {
            $locked = AppointmentRequest::query()->lockForUpdate()->findOrFail($request->id);
            Access::authorize($actor, Permission::P105, $locked);
            self::ensureEnabled();
            if ($lockVersion !== null && $lockVersion !== $locked->lock_version) {
                throw new StaleRecord($locked->lock_version);
            }
            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['request' => __('appointment_requests.validation.not_open')]);
            }
            if (! in_array($locked->kind, FieldSalesConfig::kinds(), true)) {
                throw ValidationException::withMessages(['request' => __('field_sales.validation.kind')]);
            }
            $rep = self::rep($repId, $locked->branch_id);

            $before = ['status' => $locked->status->value, 'assignee_id' => $locked->assignee_id];
            $assignment = $this->open(FieldAssignment::REQUEST, $locked->id, $locked->branch_id, $rep, $actor, $note);
            $locked->forceFill([
                'status' => AppointmentRequestStatus::Assigned, 'assignee_id' => $rep->id, 'assigned_by' => $actor->id,
                'assigned_at' => now(), 'due_at' => $assignment->due_at, 'lock_version' => $locked->lock_version + 1,
            ])->save();
            Audit::record(AuditAction::FieldRequestAssigned, $locked, $actor, before: $before,
                after: ['status' => $locked->status->value, 'assignee_id' => $rep->id, 'due_at' => $assignment->due_at?->toIso8601String()],
                source: $source, label: $locked->reference);

            if ($rep->id !== $actor->id) {
                $this->notifier->send($rep, 'field_sales.request_assigned', [
                    'reference' => (string) $locked->reference, 'name' => $locked->name,
                    'kind' => __('appointments.kind.'.$locked->kind, [], $rep->locale ?? null),
                ], $locked, "field_assignment:{$assignment->id}:assigned");
            }

            return $assignment;
        });
    }

    /**
     * Ends the subject's open assignment when the subject itself ends (converted or closed lead, booked or closed
     * request). Called by the subjects' model events, inside their writer's transaction.
     */
    public static function settle(string $subjectType, int $subjectId, string $outcome): void
    {
        FieldAssignment::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('status', FieldAssignment::OPEN)
            ->update(['status' => FieldAssignment::DONE, 'outcome' => $outcome, 'ended_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The subject now follows someone else than the open assignment's rep (another path changed its assignee): that
     * assignment is no longer the rep's task.
     */
    public static function followAssignee(string $subjectType, int $subjectId, ?int $assigneeId): void
    {
        FieldAssignment::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('status', FieldAssignment::OPEN)->where('rep_id', '!=', (int) $assigneeId)
            ->update(['status' => FieldAssignment::REASSIGNED, 'ended_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The open assignment of a subject, if any.
     */
    public static function openFor(string $subjectType, int $subjectId): ?FieldAssignment
    {
        return FieldAssignment::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('status', FieldAssignment::OPEN)->first();
    }

    private function open(string $type, int $id, int $branchId, User $rep, User $actor, ?string $note): FieldAssignment
    {
        FieldAssignment::query()->where('subject_type', $type)->where('subject_id', $id)->where('status', FieldAssignment::OPEN)
            ->update(['status' => FieldAssignment::REASSIGNED, 'ended_at' => now(), 'updated_at' => now()]);
        $hours = FieldSalesConfig::current()['response_hours'];
        $note = $note === null ? null : trim($note);

        return FieldAssignment::query()->create([
            'subject_type' => $type, 'subject_id' => $id, 'branch_id' => $branchId, 'rep_id' => $rep->id,
            'assigned_by' => $actor->id, 'assigned_at' => now(), 'due_at' => $hours === null ? null : now()->addHours($hours),
            'status' => FieldAssignment::OPEN, 'note' => $note === '' ? null : mb_substr((string) $note, 0, 500),
        ]);
    }

    private static function ensureEnabled(): void
    {
        if (! FieldSalesConfig::enabled()) {
            throw ValidationException::withMessages(['rep_id' => __('field_sales.validation.disabled')]);
        }
    }

    private static function rep(int $repId, int $branchId): User
    {
        $rep = User::query()->find($repId);
        if ($rep === null || ! FieldReps::covers($rep, $branchId)) {
            throw ValidationException::withMessages(['rep_id' => __('field_sales.validation.rep')]);
        }

        return $rep;
    }
}
