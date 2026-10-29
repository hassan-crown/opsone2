<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Models\Appointment;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

/**
 * Appointments (SHR-12): by start time in the range; a cancelled one is counted apart, never as scheduled.
 */
final class AppointmentsReport implements Report
{
    public function key(): string
    {
        return 'appointments';
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
        $r = new ReportResult('appointments', $s);
        $q = Appointment::query()->whereIn('order_id', Support::orderIds($s->orders()))
            ->whereBetween('starts_at', [Support::at($s->start()), Support::at($s->end())]);
        $live = (clone $q)->where('status', '!=', 'cancelled');

        $r->metric('appointments.scheduled', (clone $live)->count())
            ->metric('appointments.done', (clone $q)->where('status', 'done')->count())
            ->metric('appointments.no_show', (clone $live)->where('no_show', true)->count())
            ->metric('appointments.cancelled', (clone $q)->where('status', 'cancelled')->count());

        $byStatus = (clone $q)->groupBy('status')->orderBy('status')->selectRaw('status as s, COUNT(*) as c')->pluck('c', 's')->map(fn ($c) => (int) $c)->all();
        $r->chart('by_status', 'bar', 'count', Support::enumPoints($byStatus));

        $rows = (clone $q)->with('order')->orderBy('starts_at')->limit(ReportResult::ROW_CAP + 1)->get()->map(fn (Appointment $a) => [
            'order' => $a->order->number, 'kind' => Support::v($a->kind), 'status' => Support::v($a->status), 'starts_at' => Support::datetime($a->starts_at),
            'no_show' => $a->no_show ? 1 : 0,
        ])->all();

        return $r->table([
            ['key' => 'order', 'type' => 'text'], ['key' => 'kind', 'type' => 'enum'], ['key' => 'status', 'type' => 'enum'],
            ['key' => 'starts_at', 'type' => 'datetime'], ['key' => 'no_show', 'type' => 'int'],
        ], $rows);
    }
}
