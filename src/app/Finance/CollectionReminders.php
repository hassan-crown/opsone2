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
use App\Models\User;
use App\Models\WorkSettings;
use App\Money\Money;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Collection reminders to customers (FIN-11, SHR-06), through the outbox: automatic ones on the scheduler — «due soon»
 * (within 2 days, once per due date) and «overdue» (once a week) — only inside the facility's reminder hours, and the
 * bulk reminder a P-37 holder previews and sends (FinReminder). The amount is re-read at send time; the part held by
 * an open dispute is never reminded (DisputedAmounts, 6c) and a due of a closed order is not chased by the scheduler.
 */
final class CollectionReminders
{
    public const SOON_DAYS = 2;

    public function __construct(
        private readonly CollectionNotices $notices,
        private readonly DisputedAmounts $disputes,
    ) {}

    /**
     * The scheduler's run. Returns how many reminders were queued.
     */
    public function runDue(CarbonInterface $now): int
    {
        if (! WorkSettings::current()->canSendReminderAt($now)) {
            return 0;
        }
        $today = AppointmentBooking::today();
        $soonUntil = CarbonImmutable::parse($today)->addDays(self::SOON_DAYS)->toDateString();
        $week = CarbonImmutable::parse($today)->format('o-\WW');

        return LockingTransaction::run(function () use ($today, $soonUntil, $week) {
            $sent = 0;
            foreach (OpenDues::of(Order::query()) as $row) {
                if ($row['due_on'] === null || $row['order']->state()->isFinal()) {
                    continue;
                }
                $remaining = $this->remindable($row['due'], $row['remaining']);
                if ($remaining->isZero()) {
                    continue;
                }
                $sent += (int) match (true) {
                    $row['due_on'] < $today => $this->notices->dueReminder($row['due'], $row['order'], $remaining, 'overdue', $week),
                    $row['due_on'] <= $soonUntil => $this->notices->dueReminder($row['due'], $row['order'], $remaining, 'soon', $row['due_on']),
                    default => false,
                };
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
