<?php

namespace App\Automation;

use App\Enums\InterestStatus;
use App\Enums\TaskStatus;
use App\Finance\OpenDues;
use App\Jobs\ProcessAutomationEvent;
use App\Models\Automation\AutomationEvent;
use App\Models\Interest;
use App\Models\Order;
use App\Models\Task;
use App\Models\WorkSettings;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Time-based triggers (ADR-0042): a due N days ahead or N days late, a lead untouched N hours, a task N hours past its
 * SLA. The scheduler's `automation:tick` evaluates each timer once per parameter the releases use and records one
 * event per subject and anchor (the due date, the lead's last touch, the task's due time), so an hourly run raises
 * each fact once; a lead touched again and left idle again is a new fact. Runs inside the reminder hours only, like
 * the other reminders, and also re-queues events whose processing job was lost.
 */
final class AutomationTimers
{
    public const LIMIT = 500;

    public function __construct(private readonly AutomationRecorder $recorder) {}

    /**
     * Returns how many events were recorded.
     */
    public function tick(CarbonInterface $now): int
    {
        $this->requeue($now);
        if (! WorkSettings::current()->canSendReminderAt($now)) {
            return 0;
        }
        $recorded = 0;
        foreach (AutomationRules::timerParams() as $trigger => $params) {
            foreach ($params as $param) {
                foreach (self::due($trigger, $param, CarbonImmutable::parse($now)) as [$subject, $key, $context]) {
                    $recorded += (int) ($this->recorder->record($trigger, $subject, AutomationTriggers::TIMER, null, $key, null, $context) !== null);
                }
            }
        }

        return $recorded;
    }

    /**
     * The subjects a timer fires for now: `[subject, dedupe key, context]`.
     *
     * @return list<array{0: Model, 1: string, 2: array<string, mixed>}>
     */
    public static function due(string $trigger, int $param, CarbonImmutable $now): array
    {
        $out = [];
        $prefix = "timer:{$trigger}:{$param}";
        $today = AppointmentBooking::today();
        switch ($trigger) {
            case 'payment.due_soon':
            case 'payment.overdue':
                foreach (OpenDues::of(Order::query()) as $row) {
                    if ($row['due_on'] === null || $row['remaining'] <= 0 || $row['order']->state()->isFinal()) {
                        continue;
                    }
                    $before = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($row['due_on']), false);
                    $late = (int) CarbonImmutable::parse($row['due_on'])->addDays($row['due']->grace_days)->diffInDays(CarbonImmutable::parse($today), false);
                    $fires = $trigger === 'payment.due_soon' ? $before > 0 && $before <= $param : $late >= $param;
                    if ($fires) {
                        $out[] = [$row['due'], "{$prefix}:{$row['due']->id}:{$row['due_on']}", ['param' => $param, 'order_id' => $row['order']->id, 'due_on' => $row['due_on']]];
                    }
                }
                break;
            case 'lead.stale':
                $leads = Interest::query()->whereIn('status', [InterestStatus::New->value, InterestStatus::Following->value])
                    ->where('updated_at', '<=', $now->subHours($param))->orderBy('id')->limit(self::LIMIT)->get();
                foreach ($leads as $lead) {
                    $out[] = [$lead, "{$prefix}:{$lead->id}:".$lead->updated_at->getTimestamp(), ['param' => $param]];
                }
                break;
            case 'task.sla_breached':
                $tasks = Task::query()->whereIn('status', [TaskStatus::Unassigned->value, TaskStatus::Assigned->value, TaskStatus::InProgress->value])
                    ->whereNotNull('due_at')->where('due_at', '<=', $now->subHours($param))->orderBy('id')->limit(self::LIMIT)->get();
                foreach ($tasks as $task) {
                    $out[] = [$task, "{$prefix}:{$task->id}:".$task->due_at?->getTimestamp(), ['param' => $param, 'order_id' => $task->order_id]];
                }
                break;
        }

        return array_slice($out, 0, self::LIMIT);
    }

    /** Events whose job was lost (a worker restart) are queued again; the engine is idempotent. */
    private function requeue(CarbonInterface $now): void
    {
        AutomationEvent::query()->whereNull('processed_at')->where('attempts', '<', AutomationEngine::MAX_ATTEMPTS)
            ->where('occurred_at', '<', CarbonImmutable::parse($now)->subMinutes(10))->orderBy('id')->limit(self::LIMIT)
            ->pluck('id')->each(fn (int $id) => ProcessAutomationEvent::dispatch($id));
    }
}
