<?php

namespace App\FieldSales;

use App\Access\Access;
use App\Access\Permission;
use App\Models\FieldSales\FieldAssignment;
use App\Models\Interest;
use App\Models\User;
use App\Models\WorkSettings;
use App\Notifications\Notifier;
use Carbon\CarbonInterface;

/**
 * The response time of a lead dispatched to a field rep (ADR-0041): once `due_at` passed and the lead is still open
 * with the rep, `field_sales.lead_overdue` goes to the rep and to whoever dispatched it, once per assignment, inside
 * the reminder hours. A dispatched appointment request carries the same due time on the request itself, so the
 * request's own reminder (ADR-0039) covers it. Hourly from `field-sales:remind`.
 */
final class FieldAssignmentSla
{
    public function __construct(private readonly Notifier $notifier) {}

    public function run(CarbonInterface $now): int
    {
        if (! WorkSettings::current()->canSendReminderAt($now)) {
            return 0;
        }
        $sent = 0;
        $late = FieldAssignment::query()->where('status', FieldAssignment::OPEN)->where('subject_type', FieldAssignment::INTEREST)
            ->whereNotNull('due_at')->where('due_at', '<=', $now)->with('interest')->orderBy('id')->limit(500)->get();
        $users = User::query()->whereIn('id', $late->pluck('rep_id')->merge($late->pluck('assigned_by'))->filter()->unique()->all())->get()->keyBy('id');
        foreach ($late as $assignment) {
            $interest = $assignment->interest;
            if (! $interest instanceof Interest || ! $interest->isOpen()) {
                continue;
            }
            $params = ['reference' => (string) $interest->reference, 'name' => $interest->name];
            foreach (array_unique(array_filter([$assignment->rep_id, $assignment->assigned_by])) as $id) {
                $user = $users->get($id);
                if ($user !== null && (Access::can($user, Permission::P15, $interest) || Access::can($user, Permission::P105, $interest))) {
                    $sent += (int) ($this->notifier->send($user, 'field_sales.lead_overdue', $params, $interest, "field_assignment:{$assignment->id}:overdue") !== null);
                }
            }
        }

        return $sent;
    }
}
