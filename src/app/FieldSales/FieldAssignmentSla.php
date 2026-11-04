<?php

namespace App\FieldSales;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\AppointmentRequestStatus;
use App\Enums\MembershipStatus;
use App\Enums\TaskStatus;
use App\Models\AppointmentRequest;
use App\Models\FieldSales\FieldAssignment;
use App\Models\Interest;
use App\Models\Membership;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSettings;
use App\Notifications\Notifier;
use Carbon\CarbonInterface;

/**
 * What is late in the field team (ADR-0041), hourly from `field-sales:remind`, inside the reminder hours, once per
 * item and due time:
 *
 * - a lead dispatched to a rep and still open past its response time → `field_sales.lead_overdue` to the rep;
 * - the same, a dispatched appointment request not booked in time (the request's own ADR-0039 reminder still goes
 *   to its assignee and dispatcher), or an open workflow task of the rep past its due date →
 *   `field_sales.team_overdue` to every supervisor who manages the rep (P-105 over the rep's branches) and to whoever
 *   dispatched it, opening the team view.
 */
final class FieldAssignmentSla
{
    /** @var array<int, list<User>> */
    private array $supervisors = [];

    public function __construct(private readonly Notifier $notifier) {}

    public function run(CarbonInterface $now): int
    {
        if (! WorkSettings::current()->canSendReminderAt($now)) {
            return 0;
        }
        $this->supervisors = [];
        $sent = 0;
        $late = FieldAssignment::query()->where('status', FieldAssignment::OPEN)
            ->whereNotNull('due_at')->where('due_at', '<=', $now)->with(['rep', 'assigner'])->orderBy('id')->limit(500)->get();
        foreach ($late as $assignment) {
            $subject = $assignment->subject_type === FieldAssignment::INTEREST
                ? Interest::query()->find($assignment->subject_id)
                : AppointmentRequest::query()->find($assignment->subject_id);
            if ($subject === null || ($subject instanceof Interest ? ! $subject->isOpen() : $subject->status !== AppointmentRequestStatus::Assigned)) {
                continue;
            }
            $params = ['reference' => (string) $subject->reference, 'name' => $subject->name, 'rep' => $assignment->rep->name];
            if ($subject instanceof Interest && Access::can($assignment->rep, Permission::P15, $subject)) {
                $sent += (int) ($this->notifier->send($assignment->rep, 'field_sales.lead_overdue', $params, $subject, "field_assignment:{$assignment->id}:overdue") !== null);
            }
            $sent += $this->toSupervisors($assignment->rep, $assignment->assigner, $params + ['what' => $subject->reference ?? ''], "field_assignment:{$assignment->id}:team");
        }

        $tasks = Task::query()->whereIn('assignee_id', array_map(fn (User $u) => $u->id, FieldReps::all()))
            ->whereIn('status', array_map(fn ($s) => $s->value, TaskStatus::open()))
            ->whereNotNull('due_at')->where('due_at', '<=', $now)->with(['assignee', 'order'])->orderBy('id')->limit(500)->get();
        foreach ($tasks as $task) {
            if ($task->assignee === null) {
                continue;
            }
            $sent += $this->toSupervisors($task->assignee, null, ['rep' => $task->assignee->name, 'what' => $task->order->number, 'reference' => $task->order->number, 'name' => ''],
                "field_task:{$task->id}:overdue:{$task->due_at?->getTimestamp()}");
        }

        return $sent;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function toSupervisors(User $rep, ?User $dispatcher, array $params, string $key): int
    {
        $sent = 0;
        $recipients = $this->supervisorsOf($rep);
        if ($dispatcher !== null && ! in_array($dispatcher->id, array_map(fn (User $u) => $u->id, $recipients), true) && FieldRepAccess::canManage($dispatcher, $rep)) {
            $recipients[] = $dispatcher;
        }
        foreach ($recipients as $supervisor) {
            $sent += (int) ($this->notifier->send($supervisor, 'field_sales.team_overdue', $params, null, $key) !== null);
        }

        return $sent;
    }

    /**
     * Supervisors and managers who manage the rep, owner excluded (the owner sees the board without being paged).
     *
     * @return list<User>
     */
    private function supervisorsOf(User $rep): array
    {
        return $this->supervisors[$rep->id] ??= array_values(Membership::query()->with('user')->where('status', MembershipStatus::Active)
            ->whereHas('roles.template', fn ($q) => $q->where('key', 'supervisor'))->orderBy('id')->get()
            ->map(fn (Membership $m) => $m->user)->filter(fn (User $u) => FieldRepAccess::canManage($u, $rep))->values()->all());
    }
}
