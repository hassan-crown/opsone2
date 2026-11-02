<?php

namespace App\Finance;

use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DueStatus;
use App\Models\Finance\OrderDue;
use App\Models\Order;
use App\Models\ReminderSettings;
use App\Models\User;
use App\Models\WorkSettings;
use App\Money\Money;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Collection reminders to customers (FIN-11, SHR-06), through the outbox: automatic ones on the scheduler, following
 * the facility's policy (ReminderSettings, ADR-0037) — «due soon» N days before, on the due date, then «overdue» at
 * a fixed interval after the due's grace days, at most `max_after` times — and an escalation to staff after N overdue
 * days; only inside the facility's reminder hours. Plus the bulk reminder a P-37 holder previews and sends
 * (FinReminder). The amount is re-read at send time; the part held by an open dispute is never reminded
 * (DisputedAmounts, 6c) and a due of a closed order is not chased by the scheduler. Every message key carries the due,
 * its date and the step, so a run repeated in the same hour or day sends nothing twice.
 */
final class CollectionReminders
{
    public function __construct(
        private readonly CollectionNotices $notices,
        private readonly DisputedAmounts $disputes,
    ) {}

    /**
     * Which customer reminder the policy asks for today, or null: `['soon', step]`, `['today', 0]`, `['overdue', n]`.
     *
     * @return array{0: 'soon'|'today'|'overdue', 1: int}|null
     */
    public static function step(ReminderSettings $policy, string $dueOn, int $graceDays, string $today): ?array
    {
        $daysBefore = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($dueOn), false);
        if ($daysBefore > 0) {
            $steps = array_filter($policy->before_days, fn (int $d) => $d >= $daysBefore);

            return $steps === [] ? null : ['soon', min($steps)];
        }
        if ($daysBefore === 0) {
            return $policy->on_due ? ['today', 0] : null;
        }
        $late = self::daysLate($dueOn, $graceDays, $today);
        if ($late < 1) {
            return null;
        }
        $n = intdiv($late - 1, max(1, $policy->after_every_days)) + 1;

        return $n <= $policy->max_after ? ['overdue', $n] : null;
    }

    /** Days past the due date and its grace days (0 while inside them). */
    public static function daysLate(string $dueOn, int $graceDays, string $today): int
    {
        return max(0, (int) CarbonImmutable::parse($dueOn)->addDays($graceDays)->diffInDays(CarbonImmutable::parse($today), false));
    }

    /**
     * The scheduler's run. Returns how many reminders were queued.
     */
    public function runDue(CarbonInterface $now): int
    {
        if (! WorkSettings::current()->canSendReminderAt($now)) {
            return 0;
        }
        $today = AppointmentBooking::today();
        $policy = ReminderSettings::current();

        return LockingTransaction::run(function () use ($today, $policy) {
            $sent = 0;
            foreach (OpenDues::of(Order::query()) as $row) {
                if ($row['due_on'] === null || $row['order']->state()->isFinal()) {
                    continue;
                }
                $remaining = $this->remindable($row['due'], $row['remaining']);
                if ($remaining->isZero()) {
                    continue;
                }
                $grace = $row['due']->grace_days;
                $step = self::step($policy, $row['due_on'], $grace, $today);
                if ($step !== null) {
                    $sent += (int) $this->notices->dueReminder($row['due'], $row['order'], $remaining, $step[0], "{$row['due_on']}:{$step[1]}");
                }
                if ($policy->escalate_after_days !== null && self::daysLate($row['due_on'], $grace, $today) >= $policy->escalate_after_days) {
                    $this->notices->dueEscalated($row['due'], $row['order'], $remaining, (string) $policy->escalate_to, $row['due_on']);
                }
            }

            return $sent;
        });
    }

    /**
     * FinReminder preview: who would get what, and who is left out and why.
     *
     * @param  list<int>  $dueIds
     * @return list<array<string, mixed>>
     */
    public function preview(User $viewer, array $dueIds): array
    {
        $rows = [];
        foreach ($this->selected($viewer, $dueIds) as $row) {
            $remaining = $this->remindable($row['due'], $row['remaining']);
            $rows[] = [
                'due_id' => $row['due']->id,
                'order' => ['id' => $row['order']->id, 'number' => $row['order']->number],
                'customer' => $row['order']->customer->name,
                'channels' => $row['order']->customer->email ? ['sms', 'mail'] : ['sms'],
                'amount_minor' => $remaining->minor,
                'excluded' => $remaining->isZero() ? ($row['remaining'] > 0 ? 'disputed' : 'nothing_due') : null,
            ];
        }

        return $rows;
    }

    /**
     * Sends the bulk reminder, re-checking each amount now. Returns how many were queued.
     *
     * @param  list<int>  $dueIds
     */
    public function send(User $viewer, array $dueIds): int
    {
        return LockingTransaction::run(function () use ($viewer, $dueIds) {
            $sent = 0;
            $day = AppointmentBooking::today();
            foreach ($this->selected($viewer, $dueIds) as $row) {
                $remaining = $this->remindable($row['due'], $row['remaining']);
                if (! $remaining->isZero() && $this->notices->dueReminder($row['due'], $row['order'], $remaining, 'overdue', 'manual-'.$day)) {
                    $sent++;
                }
            }
            Audit::record(AuditAction::CollectionRemindersSent, null, $viewer, after: ['dues' => $dueIds, 'sent' => $sent], source: TransitionRecorder::SOURCE_WEB);

            return $sent;
        });
    }

    private function remindable(OrderDue $due, int $remaining): Money
    {
        return Money::of($remaining)->minus($this->disputes->disputedFor($due))->max(Money::zero());
    }

    /**
     * @param  list<int>  $dueIds
     * @return list<array{due: OrderDue, order: Order, due_on: string|null, status: DueStatus, remaining: int}>
     */
    private function selected(User $viewer, array $dueIds): array
    {
        $orders = ScopedQuery::apply(Order::query(), $viewer, [Permission::P37])
            ->whereIn('orders.id', OrderDue::query()->whereIn('id', $dueIds)->select('order_id'));

        return array_values(array_filter(OpenDues::of($orders), fn (array $row) => in_array($row['due']->id, $dueIds, true)));
    }
}
