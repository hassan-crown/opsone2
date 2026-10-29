<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Reports\CollectionMetrics;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

/**
 * Collections and receivables aging (SHR-12, FIN-01): all money, so it needs P-37 over each order.
 */
final class CollectionAgingReport implements Report
{
    public function key(): string
    {
        return 'collection';
    }

    public function requires(): Permission
    {
        return Permission::P37;
    }

    public function capability(): ?string
    {
        return null;
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('collection', $s);
        $orders = $s->moneyOrders();
        $m = CollectionMetrics::movements($orders, $s->start(), $s->end());
        $aging = CollectionMetrics::aging($orders);

        $r->metric('collection.collected_minor', $m['collected'])
            ->metric('collection.corrections_minor', $m['corrections'])
            ->metric('collection.transfers_minor', $m['transfers'])
            ->metric('collection.refunded_minor', $m['refunded'])
            ->metric('collection.net_minor', $m['net'])
            ->metric('collection.overdue_minor', $aging['overdue_minor'])
            ->metric('collection.open_minor', $aging['open_minor']);

        $r->chart('aging', 'bar', 'money', array_map(
            fn (string $b) => ['label' => $b, 'value' => $aging['buckets'][$b]['amount_minor'], 'enum' => true],
            CollectionMetrics::BUCKETS,
        ));
        $days = CollectionMetrics::netByDay($orders, $s->start(), $s->end());
        $r->chart('net_by_day', 'line', 'money', array_map(fn ($d, $v) => ['label' => $d, 'value' => $v], array_keys($days), array_values($days)));

        $rows = array_map(fn (array $o) => [
            'order' => $o['order'], 'customer' => $o['customer'], 'due' => $o['due'], 'due_on' => $o['due_on'],
            'amount_minor' => $o['amount_minor'], 'age_days' => $o['age_days'], 'bucket' => $o['bucket'],
        ], $aging['overdue']);

        return $r->table([
            ['key' => 'order', 'type' => 'text'], ['key' => 'customer', 'type' => 'text'], ['key' => 'due', 'type' => 'text'],
            ['key' => 'due_on', 'type' => 'date'], ['key' => 'amount_minor', 'type' => 'money'], ['key' => 'age_days', 'type' => 'int'],
            ['key' => 'bucket', 'type' => 'enum'],
        ], $rows);
    }
}
