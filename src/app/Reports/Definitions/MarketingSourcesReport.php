<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Models\Agreements\Agreement;
use App\Models\AppointmentRequest;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\Marketing\MarketingTouch;
use App\Models\Order;
use App\Models\Store\StoreCheckout;
use App\Reports\ReportResult;
use App\Reports\ReportScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Sales and customers by source and campaign (ADR-0045, license `marketing_reports` — the «growth» plan and up, Q-77).
 * Leads, appointment requests, orders and booked sales are counted on their **last** touch (the visit from a campaign
 * or another site that led to them); new customers on their **first** touch. Only records that came through the site
 * or store carry touches, so walk-in and phone business is not in this report. Booked money needs P-37.
 */
final class MarketingSourcesReport implements Report
{
    public function key(): string
    {
        return 'marketing';
    }

    public function requires(): ?Permission
    {
        return null;
    }

    public function capability(): string
    {
        return 'marketing_reports';
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('marketing', $s);
        $start = Support::at($s->start());
        $end = Support::at($s->end());
        $branches = $s->visibleBranchIds();
        $rows = [];

        $count = function (Builder $records, string $table, string $kind, string $column) use (&$rows, $start, $end, $branches): int {
            /** @var Model $model */
            $model = $records->getModel();
            $grouped = $records->whereBetween("$table.created_at", [$start, $end])->whereIn("$table.branch_id", $branches)
                ->join('marketing_touches as t', fn ($j) => $j->on('t.touchable_id', '=', "$table.id")
                    ->where('t.touchable_type', $model->getMorphClass())->where('t.kind', $kind))
                ->groupBy('t.source', 't.medium', 't.campaign')
                ->selectRaw('t.source, t.medium, t.campaign, COUNT(*) as n')->toBase()->get();
            $total = 0;
            foreach ($grouped as $g) {
                self::row($rows, (string) $g->source, (string) $g->medium, $g->campaign)[$column] += (int) $g->n;
                $total += (int) $g->n;
            }

            return $total;
        };

        $leads = $count(Interest::query(), 'interests', MarketingTouch::LAST, 'leads');
        $requests = $count(AppointmentRequest::query(), 'appointment_requests', MarketingTouch::LAST, 'requests');
        $customers = $count(Customer::query(), 'customers', MarketingTouch::FIRST, 'customers');
        $orders = $count(clone $s->orders(), 'orders', MarketingTouch::LAST, 'orders');
        $booked = $s->amountsVisible() ? $this->booked($s, $start, $end, $rows) : 0;

        $r->metric('marketing.leads', $leads)
            ->metric('marketing.appointment_requests', $requests)
            ->metric('marketing.new_customers', $customers)
            ->metric('marketing.orders', $orders)
            ->metric('marketing.booked_minor', $booked);

        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['booked_minor'], $b['orders'], $b['leads']] <=> [$a['booked_minor'], $a['orders'], $a['leads']]);

        $bySource = [];
        $bookedBySource = [];
        foreach ($rows as $row) {
            $bySource[$row['source']] = ($bySource[$row['source']] ?? 0) + $row['leads'] + $row['requests'];
            $bookedBySource[$row['source']] = ($bookedBySource[$row['source']] ?? 0) + $row['booked_minor'];
        }
        arsort($bySource);
        arsort($bookedBySource);
        $points = fn (array $values) => array_map(fn ($k, $v) => ['label' => (string) $k, 'value' => $v], array_keys($values), $values);
        $r->chart('leads_by_source', 'bar', 'count', $points(array_slice($bySource, 0, 10, true)));
        $r->chart('booked_by_source', 'bar', 'money', $points(array_slice($bookedBySource, 0, 10, true)));

        return $r->table([
            ['key' => 'source', 'type' => 'text'], ['key' => 'medium', 'type' => 'text'], ['key' => 'campaign', 'type' => 'text'],
            ['key' => 'leads', 'type' => 'int'], ['key' => 'requests', 'type' => 'int'], ['key' => 'customers', 'type' => 'int'],
            ['key' => 'orders', 'type' => 'int'], ['key' => 'booked_minor', 'type' => 'money'],
        ], $rows);
    }

    /**
     * Booked sales of orders with a last touch: store checkouts paid in range + custom-order agreements first
     * approved in range (the same definition as `channels.booked_minor`), over the orders whose money the viewer sees.
     *
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function booked(ReportScope $s, string $start, string $end, array &$rows): int
    {
        $touches = MarketingTouch::query()->where('touchable_type', (new Order)->getMorphClass())->where('kind', MarketingTouch::LAST)
            ->whereIn('touchable_id', Support::orderIds((clone $s->moneyOrders())->where('orders.status', '!=', 'cancelled')))
            ->get(['touchable_id', 'source', 'medium', 'campaign'])->keyBy('touchable_id');
        if ($touches->isEmpty()) {
            return 0;
        }
        $ids = $touches->keys()->all();
        $store = StoreCheckout::query()->where('status', 'paid')->whereBetween('paid_at', [$start, $end])->whereIn('order_id', $ids)
            ->groupBy('order_id')->selectRaw('order_id, SUM(total_minor) as t')->toBase()->pluck('t', 'order_id');
        $agreed = Agreement::query()->where('status', 'active')->whereIn('order_id', $ids)->whereIn('order_id', SalesReport::firstApproved($start, $end))
            ->groupBy('order_id')->selectRaw('order_id, SUM(total_minor) as t')->toBase()->pluck('t', 'order_id');

        $total = 0;
        foreach ($touches as $orderId => $t) {
            $amount = (int) ($store[$orderId] ?? 0) + (int) ($agreed[$orderId] ?? 0);
            if ($amount > 0) {
                self::row($rows, $t->source, $t->medium, $t->campaign)['booked_minor'] += $amount;
                $total += $amount;
            }
        }

        return $total;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function &row(array &$rows, string $source, string $medium, ?string $campaign): array
    {
        $key = $source."\0".$medium."\0".($campaign ?? '');
        if (! isset($rows[$key])) {
            $rows[$key] = ['source' => $source, 'medium' => $medium, 'campaign' => $campaign, 'leads' => 0, 'requests' => 0, 'customers' => 0, 'orders' => 0, 'booked_minor' => 0];
        }

        return $rows[$key];
    }
}
