<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Models\OrderStage;
use App\Models\Task;
use App\Reports\ReportResult;
use App\Reports\ReportScope;
use Carbon\CarbonImmutable;

/**
 * Workflow throughput and overdue tasks (SHR-12). A task without a due date is never overdue.
 */
final class WorkflowReport implements Report
{
    public function key(): string
    {
        return 'workflow';
    }

    public function requires(): ?Permission
    {
        return null;
    }

    public function capability(): ?string
    {
        return null;
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('workflow', $s);
        $ids = Support::orderIds($s->orders());
        $start = Support::at($s->start());
        $end = Support::at($s->end());
        $tasks = Task::query()->whereIn('order_id', $ids);

        $done = (clone $tasks)->where('status', 'completed')->whereBetween('completed_at', [$start, $end])->get();
        $hours = $done->isEmpty() ? 0.0 : round($done->sum(fn (Task $t) => $t->created_at->diffInSeconds($t->completed_at, true)) / 3600 / $done->count(), 1);
        $now = Support::at(CarbonImmutable::now());
        $overdue = (clone $tasks)->whereNotIn('status', ['completed', 'cancelled'])->whereNotNull('due_at')->where('due_at', '<', $now);

        $r->metric('workflow.tasks_completed', $done->count())
            ->metric('workflow.avg_completion_hours', $hours)
            ->metric('workflow.tasks_overdue', (clone $overdue)->count())
            ->metric('workflow.stages_completed', OrderStage::query()->whereIn('order_id', $ids)->where('status', 'completed')->whereBetween('completed_at', [$start, $end])->count());

        $byKind = $done->groupBy('kind')->map->count()->sortKeys()->all();
        $r->chart('completed_by_kind', 'bar', 'count', Support::enumPoints($byKind));

        $rows = (clone $overdue)->with('order')->orderBy('due_at')->limit(ReportResult::ROW_CAP + 1)->get()->map(fn (Task $t) => [
            'order' => $t->order->number, 'task' => $t->title->get(), 'kind' => Support::v($t->kind), 'status' => Support::v($t->status),
            'due_on' => Support::date($t->due_at), 'days_overdue' => (int) $t->due_at->diffInDays(CarbonImmutable::now(), true),
        ])->all();

        return $r->table([
            ['key' => 'order', 'type' => 'text'], ['key' => 'task', 'type' => 'text'], ['key' => 'kind', 'type' => 'enum'], ['key' => 'status', 'type' => 'enum'],
            ['key' => 'due_on', 'type' => 'date'], ['key' => 'days_overdue', 'type' => 'int'],
        ], $rows);
    }
}
