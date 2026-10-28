<?php

namespace App\Reports;

use App\Enums\JournalKind;
use App\Finance\OpenDues;
use App\Models\Order;
use App\Reports\Definitions\Support;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The collection numbers, defined once (docs/p9-metrics.md): ledger movements by journal kind in a range and the
 * receivables aging. The P6b ReportCollection screen and the P9 report both read them from here.
 */
final class CollectionMetrics
{
    public const BUCKETS = ['not_overdue', 'd1_7', 'd8_30', 'd30_plus'];

    /**
     * Sum of money entries per journal kind created in [start, end] on the given orders.
     *
     * @param  Builder<Order>  $orders  already narrowed to what the reader may see money of
     * @return array{collected: int, corrections: int, transfers: int, refunded: int, net: int}
     */
    public static function movements(Builder $orders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('money_entries')->join('money_journals', 'money_journals.id', '=', 'money_entries.journal_id')
            ->whereIn('money_entries.order_id', Support::orderIds($orders))
            ->whereBetween('money_journals.created_at', [Support::at($start), Support::at($end)])
            ->groupBy('money_journals.kind')->selectRaw('money_journals.kind as k, SUM(money_entries.amount_minor) as s')->pluck('s', 'k');
        $get = fn (JournalKind $k) => (int) ($rows[$k->value] ?? 0);
        $collected = $get(JournalKind::Collection);
        $corrections = $get(JournalKind::Reversal);
        $transfers = $get(JournalKind::Transfer);
        $refunded = $get(JournalKind::Refund);

        return [
            'collected' => $collected, 'corrections' => $corrections, 'transfers' => $transfers, 'refunded' => $refunded,
            'net' => $collected + $corrections + $transfers + $refunded,
        ];
    }

    /**
     * Net movement per facility day, for the trend chart.
     *
     * @param  Builder<Order>  $orders
     * @return array<string, int>
     */
    public static function netByDay(Builder $orders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('money_entries')->join('money_journals', 'money_journals.id', '=', 'money_entries.journal_id')
            ->whereIn('money_entries.order_id', Support::orderIds($orders))
            ->whereBetween('money_journals.created_at', [Support::at($start), Support::at($end)])
            ->groupBy('money_journals.id', 'money_journals.created_at')
            ->selectRaw('money_journals.created_at as t, SUM(money_entries.amount_minor) as s')->get();
        $days = [];
        foreach ($rows as $row) {
            $d = (string) Support::date($row->t);
            $days[$d] = ($days[$d] ?? 0) + (int) $row->s;
        }
        ksort($days);

        return $days;
    }

    /**
     * Open dues aged by their derived due date: not overdue, 1–7, 8–30, over 30 days.
     *
     * @param  Builder<Order>  $orders
     * @return array{buckets: array<string, array{amount_minor: int, count: int}>, overdue: list<array<string, mixed>>, overdue_minor: int, open_minor: int}
     */
    public static function aging(Builder $orders): array
    {
        $today = AppointmentBooking::today();
        $buckets = array_fill_keys(self::BUCKETS, ['amount_minor' => 0, 'count' => 0]);
        $overdue = [];
        $open = 0;
        foreach (OpenDues::of(clone $orders) as $row) {
            if ($row['due_on'] === null) {
                continue;
            }
            $age = $row['due_on'] < $today ? (int) CarbonImmutable::parse($row['due_on'])->diffInDays(CarbonImmutable::parse($today)) : 0;
            $bucket = match (true) {
                $age === 0 => 'not_overdue',
                $age <= 7 => 'd1_7',
                $age <= 30 => 'd8_30',
                default => 'd30_plus',
            };
            $buckets[$bucket]['amount_minor'] += $row['remaining'];
            $buckets[$bucket]['count']++;
            $open += $row['remaining'];
            if ($age > 0) {
                $overdue[] = [
                    'order' => $row['order']->number, 'order_id' => $row['order']->id, 'customer' => $row['order']->customer->name,
                    'due' => $row['due']->name->get(), 'due_on' => $row['due_on'], 'amount_minor' => $row['remaining'],
                    'age_days' => $age, 'bucket' => $bucket,
                ];
            }
        }
        usort($overdue, fn (array $a, array $b) => $b['age_days'] <=> $a['age_days']);

        return ['buckets' => $buckets, 'overdue' => $overdue, 'overdue_minor' => array_sum(array_column($overdue, 'amount_minor')), 'open_minor' => $open];
    }
}
