<?php

namespace App\Reports\Dashboard;

use App\Installation\InstallationStatus;
use App\Models\Department;
use App\Organization\OrgPresenter;
use App\Reports\Definitions\Support;
use App\Reports\ReportScope;
use Illuminate\Support\Facades\DB;

/**
 * Installation figures of the owner dashboard (ADR-0036, `dashboard.installation_*` in MetricCatalogue): over the
 * installation visits FINISHED in the range on orders in the viewer's scope —
 *
 * - first-visit completion: the visit is the order's first installation visit and it finished without a remark
 *   (status `finished`); share × 100, one decimal;
 * - average duration: arrival → finish in whole minutes, rounded half up;
 * - per team: the same two numbers by the department of the installation task the appointment serves, else of the
 *   member who finished it («no team» when neither has one).
 *
 * Absent when no visit finished in range (never a misleading 0).
 */
final class InstallationMetrics
{
    /**
     * @return array{count: int, first_visit_percent: float|null, avg_minutes: int|null, teams: list<array{id: int|null, name: string, visits: int, first_visit_percent: float, avg_minutes: int}>}
     */
    public static function build(ReportScope $s): array
    {
        $rows = DB::table('installation_visits as v')
            ->join('appointments as a', 'a.id', '=', 'v.appointment_id')
            ->leftJoin('tasks as t', 't.id', '=', 'a.task_id')
            ->leftJoin('memberships as m', 'm.user_id', '=', 'v.finished_by')
            ->whereIn('v.order_id', Support::orderIds($s->orders()))
            ->whereNotNull('v.finished_at')
            ->whereBetween('v.finished_at', [Support::at($s->start()), Support::at($s->end())])
            ->selectRaw('v.id, v.order_id, v.status, v.arrived_at, v.finished_at, COALESCE(t.department_id, m.department_id) as team')
            ->orderBy('v.id')->get();
        if ($rows->isEmpty()) {
            return ['count' => 0, 'first_visit_percent' => null, 'avg_minutes' => null, 'teams' => []];
        }

        // The earliest arrival per order among all its installation visits (an earlier, unfinished one counts).
        $firsts = DB::table('installation_visits')->whereIn('order_id', $rows->pluck('order_id')->unique()->values()->all())
            ->groupBy('order_id')->selectRaw('order_id, MIN(id) as first_id')->pluck('first_id', 'order_id');

        $facts = $rows->map(fn ($r) => [
            'team' => $r->team === null ? null : (int) $r->team,
            'first' => (int) ($firsts[$r->order_id] ?? 0) === (int) $r->id && $r->status === InstallationStatus::Finished->value,
            'seconds' => max(0, strtotime((string) $r->finished_at) - strtotime((string) $r->arrived_at)),
        ]);

        $summary = fn ($set): array => [
            'visits' => (int) $set->count(),
            'first_visit_percent' => round($set->where('first', true)->count() * 100 / $set->count(), 1),
            'avg_minutes' => (int) round($set->sum('seconds') / $set->count() / 60),
        ];
        $all = $summary($facts);
        $names = Department::query()->whereIn('id', $facts->pluck('team')->filter()->unique()->values()->all())->get()
            ->mapWithKeys(fn (Department $d) => [$d->id => OrgPresenter::name($d)])->all();

        $teams = [];
        foreach ($facts->groupBy(fn (array $f) => (string) ($f['team'] ?? '')) as $team => $set) {
            $team = (string) $team;
            $teams[] = [
                'id' => $team === '' ? null : (int) $team,
                'name' => $team === '' ? (string) __('executive.installation.no_team') : ($names[(int) $team] ?? ''),
            ] + $summary($set);
        }
        usort($teams, fn (array $a, array $b) => $b['visits'] <=> $a['visits']);

        return ['count' => (int) $all['visits'], 'first_visit_percent' => $all['first_visit_percent'], 'avg_minutes' => $all['avg_minutes'], 'teams' => $teams];
    }
}
