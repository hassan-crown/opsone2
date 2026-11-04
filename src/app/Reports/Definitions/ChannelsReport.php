<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Enums\SalesChannel;
use App\Models\Agreements\Agreement;
use App\Models\Store\StoreCheckout;
use App\Reports\CollectionMetrics;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

/**
 * Sales by channel (ADR-0041): showroom, online store and field sales side by side — orders created, booked sales
 * (first agreement approved / store checkout paid in range) and money collected in range, each from its own source
 * and split by `orders.sales_channel`. Amounts need P-37.
 */
final class ChannelsReport implements Report
{
    public function key(): string
    {
        return 'channels';
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
        $r = new ReportResult('channels', $s);
        $start = Support::at($s->start());
        $end = Support::at($s->end());

        $created = (clone $s->orders())->whereBetween('orders.created_at', [$start, $end])
            ->groupBy('orders.sales_channel')->selectRaw('orders.sales_channel as c, COUNT(*) as n')->toBase()->pluck('n', 'c');
        $booked = self::booked($s, $start, $end);
        $rows = [];
        $totalOrders = 0;
        $totalBooked = 0;
        $totalCollected = 0;
        foreach (SalesChannel::cases() as $channel) {
            $orders = (int) ($created[$channel->value] ?? 0);
            $collected = $s->amountsVisible()
                ? CollectionMetrics::movements((clone $s->moneyOrders())->where('orders.sales_channel', $channel->value), $s->start(), $s->end())['net']
                : 0;
            $totalOrders += $orders;
            $totalBooked += $booked[$channel->value];
            $totalCollected += $collected;
            $rows[] = ['channel' => $channel->value, 'orders' => $orders, 'booked_minor' => $booked[$channel->value], 'collected_minor' => $collected];
        }

        $r->metric('channels.orders_created', $totalOrders)
            ->metric('channels.booked_minor', $totalBooked)
            ->metric('channels.collected_minor', $totalCollected)
            ->metric('channels.field_sales_share_percent', $totalBooked === 0 ? 0.0 : round($booked[SalesChannel::FieldSales->value] * 100 / $totalBooked, 1));

        $r->chart('orders_by_channel', 'bar', 'count', Support::enumPoints(array_column($rows, 'orders', 'channel')));
        $r->chart('booked_by_channel', 'bar', 'money', Support::enumPoints(array_column($rows, 'booked_minor', 'channel')));

        return $r->table([
            ['key' => 'channel', 'type' => 'enum'], ['key' => 'orders', 'type' => 'int'],
            ['key' => 'booked_minor', 'type' => 'money'], ['key' => 'collected_minor', 'type' => 'money'],
        ], $rows);
    }

    /**
     * Booked sales per channel: active agreements of custom orders first approved in range plus store checkouts paid
     * in range, over the orders whose money the viewer sees.
     *
     * @return array<string, int>
     */
    public static function booked(ReportScope $s, string $start, string $end, ?int $sellerId = null): array
    {
        $out = array_fill_keys(SalesChannel::values(), 0);
        if (! $s->amountsVisible()) {
            return $out;
        }
        $orders = Support::orderIds((clone $s->moneyOrders())->where('orders.status', '!=', 'cancelled')
            ->when($sellerId !== null, fn ($q) => $q->where('orders.seller_id', $sellerId)));
        $agreed = Agreement::query()->where('agreements.status', 'active')
            ->join('orders', 'orders.id', '=', 'agreements.order_id')
            ->whereIn('agreements.order_id', $orders)->whereIn('agreements.order_id', SalesReport::firstApproved($start, $end))
            ->groupBy('orders.sales_channel')->selectRaw('orders.sales_channel as c, COALESCE(SUM(agreements.total_minor),0) as t')->toBase()->pluck('t', 'c');
        $store = StoreCheckout::query()->where('store_checkouts.status', 'paid')->whereBetween('store_checkouts.paid_at', [$start, $end])
            ->join('orders', 'orders.id', '=', 'store_checkouts.order_id')->whereIn('store_checkouts.order_id', $orders)
            ->groupBy('orders.sales_channel')->selectRaw('orders.sales_channel as c, COALESCE(SUM(store_checkouts.total_minor),0) as t')->toBase()->pluck('t', 'c');
        foreach ($out as $channel => $value) {
            $out[$channel] = (int) ($agreed[$channel] ?? 0) + (int) ($store[$channel] ?? 0);
        }

        return $out;
    }
}
