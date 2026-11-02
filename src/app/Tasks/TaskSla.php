<?php

namespace App\Tasks;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\TaskStatus;
use App\Models\Facility;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSettings;
use App\Notifications\Notifier;
use Carbon\CarbonInterface;

/**
 * Task SLA (ADR-0037): a new task's `due_at` is N working days after its creation — N per action type, else the
 * facility default (WorkSettings `task_sla`; 3 working days before it is saved). It is fixed at creation: changing
 * the setting never moves existing tasks. The scheduler sends the defined `work.reminder` to the assignee on the due
 * day and, once a task is `task_escalate_after_days` working days late, `work.escalated` to its follower (else the
 * order's responsible person, else the owner). Each message once per task and due time, inside the reminder hours.
 */
final class TaskSla
{
    public const DEFAULT_DAYS = 3;

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * Working days for a new task of this action type, or null when the facility switched SLAs off.
     */
    public static function days(?string $type): ?int
    {
        $sla = WorkSettings::current()->task_sla;
        if ($sla === null) {
            return self::DEFAULT_DAYS;
        }
        if (($sla['enabled'] ?? true) === false) {
            return null;
        }
        $byType = is_array($sla['by_type'] ?? null) ? $sla['by_type'] : [];

        return (int) ($type !== null && isset($byType[$type]) ? $byType[$type] : ($sla['default'] ?? self::DEFAULT_DAYS));
    }

    public static function dueAt(?string $type, CarbonInterface $createdAt): ?CarbonInterface
    {
        $days = self::days($type);

        return $days === null ? null : WorkingDays::current()->dueAt($createdAt, $days);
    }

    /**
     * The scheduler's run. Returns how many notices were created.
     */
    public function run(CarbonInterface $now): int
    {
        $settings = WorkSettings::current();
        if (! $settings->canSendReminderAt($now)) {
            return 0;
        }
        $days = WorkingDays::current();
        $sent = 0;
        $tasks = Task::query()->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, TaskStatus::open()))->whereNotNull('due_at')
            ->where('due_at', '<=', $now->copy()->addDay())->with(['order', 'assignee'])->orderBy('id')->get();
        foreach ($tasks as $task) {
            $params = ['order' => $task->order->number, 'task' => (string) $task->title->get(), 'due' => $task->due_at->copy()->setTimezone(self::timezone())->format('Y-m-d H:i')];
            $stamp = $task->due_at->getTimestamp();
            if ($task->assignee !== null) {
                $sent += (int) ($this->notifier->send($task->assignee, 'work.reminder', $params, $task, "task:{$task->id}:reminder:{$stamp}") !== null);
            }
            $late = $task->due_at->isPast() ? $days->between($task->due_at, $now) : 0;
            if ($late >= max(1, $settings->task_escalate_after_days)) {
                $to = $this->escalationTarget($task);
                if ($to !== null) {
                    $sent += (int) ($this->notifier->send($to, 'work.escalated', [...$params, 'assignee' => (string) ($task->assignee->name ?? '—')], $task, "task:{$task->id}:escalated:{$stamp}") !== null);
                }
            }
        }

        return $sent;
    }

    /**
     * The first of the task's follower, the order's responsible person and the owner who is not the assignee and may
     * act on the task.
     */
    private function escalationTarget(Task $task): ?User
    {
        $owner = Facility::current()?->owner?->user;
        foreach ([$task->follow_up_user_id, $task->order->assignee_id, $owner?->id] as $id) {
            if ($id === null || $id === $task->assignee_id) {
                continue;
            }
            $candidate = $id === $owner?->id ? $owner : User::query()->find($id);
            if ($candidate !== null && Access::can($candidate, Permission::P24, $task)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function timezone(): string
    {
        return Facility::current()?->timezone ?: (string) config('app.timezone');
    }
}
