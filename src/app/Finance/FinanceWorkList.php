<?php

namespace App\Finance;

use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Enums\DueStatus;
use App\Finance\Settlement\SettlementWorkItems;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\PaymentProof;
use App\Models\Order;
use App\Models\User;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;

/**
 * FinHome (FIN-11): one list of what is due, overdue, waiting for a proof decision or holding money that needs
 * allocation or settlement, oldest first, scoped by P-37 over each order (a list never shows what opening the order
 * would refuse). Refunds not yet executed and open disputes come from 6c (SettlementWorkItems).
 */
final class FinanceWorkList
{
    public const KINDS = ['overdue', 'due', 'proof', 'settlement', ...SettlementWorkItems::KINDS];

    /**
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public static function for(User $viewer): array
    {
        $orders = ScopedQuery::apply(Order::query(), $viewer, [Permission::P37]);
        $today = AppointmentBooking::today();
        $items = [];

        foreach (OpenDues::of(clone $orders) as $row) {
            if (! in_array($row['status'], [DueStatus::Due, DueStatus::Overdue, DueStatus::PartiallyPaid], true) || $row['due_on'] === null || $row['due_on'] > $today) {
                continue;
            }
            $overdue = $row['due_on'] < $today;
            $items[] = [
                'kind' => $overdue ? 'overdue' : 'due',
                'key' => 'due-'.$row['due']->id,
                'due_id' => $row['due']->id,
                'order' => self::order($row['order']),
                'label' => $row['due']->name->get(),
                'date' => $row['due_on'],
                'age_days' => (int) CarbonImmutable::parse($row['due_on'])->diffInDays(CarbonImmutable::parse($today)),
                'amount_minor' => $row['remaining'],
            ];
        }

        $proofs = PaymentRegistry::openProofs()->with(['order.customer'])->where('payment_proofs.status', 'under_review')
            ->whereIn('payment_proofs.order_id', (clone $orders)->select('orders.id'))->orderBy('payment_proofs.id')->limit(200)->get();
        foreach ($proofs as $proof) {
            /** @var PaymentProof $proof */
            $items[] = [
                'kind' => 'proof',
                'key' => 'proof-'.$proof->id,
                'proof_id' => $proof->id,
                'order' => self::order($proof->order),
                'label' => $proof->reference,
                'date' => $proof->created_at->toDateString(),
                'age_days' => (int) $proof->created_at->diffInDays(now()),
                'amount_minor' => $proof->claimed_minor,
            ];
        }

        // Money on an order that no due holds: a deposit before the agreement, or an overpayment to allocate or settle.
        $credits = MoneyEntry::query()->whereNull('order_due_id')->whereIn('order_id', (clone $orders)->select('orders.id'))
            ->groupBy('order_id')->havingRaw('SUM(amount_minor) > 0')->selectRaw('order_id, SUM(amount_minor) as credit, MAX(created_at) as since')
            ->limit(200)->get();
        $creditOrders = Order::query()->with('customer')->whereIn('id', $credits->pluck('order_id'))->get()->keyBy('id');
        foreach ($credits as $credit) {
            $order = $creditOrders->get((int) $credit->getAttribute('order_id'));
            if ($order === null) {
                continue;
            }
            $since = CarbonImmutable::parse((string) $credit->getAttribute('since'));
            $items[] = [
                'kind' => 'settlement',
                'key' => 'credit-'.$order->id,
                'order' => self::order($order),
                'label' => null,
                'date' => $since->toDateString(),
                'age_days' => (int) $since->diffInDays(now()),
                'amount_minor' => (int) $credit->getAttribute('credit'),
            ];
        }

        array_push($items, ...SettlementWorkItems::for(clone $orders));

        $rank = ['overdue' => 0, 'proof' => 1, 'refund' => 2, 'due' => 3, 'dispute' => 4, 'settlement' => 5];
        usort($items, fn (array $a, array $b) => [$rank[$a['kind']], $a['date']] <=> [$rank[$b['kind']], $b['date']]);
        $counts = array_fill_keys(self::KINDS, 0);
        foreach ($items as $item) {
            $counts[$item['kind']]++;
        }

        return ['items' => $items, 'counts' => $counts];
    }

    /**
     * @return array{id: int, number: string, customer: string}
     */
    private static function order(Order $order): array
    {
        return ['id' => $order->id, 'number' => $order->number, 'customer' => $order->customer->name];
    }
}
