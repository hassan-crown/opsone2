<?php

namespace App\Finance;

use App\Enums\JournalKind;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Payment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Allocation (FIN-06): the order's credit goes to its dues oldest first (schedule position), in exact halalas and
 * never above what a due still lacks; the oldest payment's credit is used first. What no due needs stays as credit —
 * an overpayment is held, never lost and never raises the agreement. `trim()` takes back what a due holds above its
 * (new) amount, newest payment first, so a lowered agreement or schedule never leaves a due over-allocated.
 * Call under the order lock.
 */
final class Allocator
{
    public function __construct(private readonly MoneyLedger $ledger) {}

    /**
     * @return int halalas allocated by this call
     */
    public function settle(Order $order, ?User $actor, string $source): int
    {
        $dues = self::openDues($order->id);
        if ($dues->isEmpty()) {
            return 0;
        }
        $allocated = MoneyLedger::allocatedByDue($order->id);
        $lacking = [];
        foreach ($dues as $due) {
            $lacking[$due->id] = max(0, $due->amount_minor - ($allocated[$due->id] ?? 0));
        }

        $total = 0;
        foreach (MoneyLedger::creditByPayment($order->id) as $paymentId => $credit) {
            $entries = [];
            foreach ($lacking as $dueId => $need) {
                if ($credit === 0) {
                    break;
                }
                $take = min($need, $credit);
                if ($take > 0) {
                    $entries[] = ['order_id' => $order->id, 'due_id' => $dueId, 'amount' => $take];
                    $lacking[$dueId] -= $take;
                    $credit -= $take;
                }
            }
            if ($entries === []) {
                continue;
            }
            $moved = array_sum(array_column($entries, 'amount'));
            $entries[] = ['order_id' => $order->id, 'due_id' => null, 'amount' => -$moved];
            $this->ledger->write(JournalKind::Allocation, Payment::query()->findOrFail($paymentId), $entries, $actor, $source);
            $total += $moved;
        }

        return $total;
    }

    /**
     * Moves back to credit what dues hold above their amount (removed dues hold nothing).
     */
    public function trim(Order $order, ?User $actor, string $source): void
    {
        $dues = OrderDue::query()->where('order_id', $order->id)->get()->keyBy('id');
        $allocated = MoneyLedger::allocatedByDue($order->id);

        foreach ($allocated as $dueId => $held) {
            $due = $dues->get($dueId);
            $limit = $due === null || $due->removed_at !== null ? 0 : $due->amount_minor;
            $excess = $held - $limit;
            if ($excess <= 0) {
                continue;
            }
            // Newest payment first: the oldest money keeps covering the oldest dues.
            $byPayment = Payment::query()->whereIn('id', MoneyEntry::query()->where('order_due_id', $dueId)->select('payment_id'))
                ->orderByDesc('paid_on')->orderByDesc('id')->get();
            foreach ($byPayment as $payment) {
                if ($excess === 0) {
                    break;
                }
                $onDue = MoneyLedger::placesOf($payment->id, $order->id)[$dueId] ?? 0;
                $take = min($onDue, $excess);
                if ($take <= 0) {
                    continue;
                }
                $this->ledger->write(JournalKind::Deallocation, $payment, [
                    ['order_id' => $order->id, 'due_id' => $dueId, 'amount' => -$take],
                    ['order_id' => $order->id, 'due_id' => null, 'amount' => $take],
                ], $actor, $source);
                $excess -= $take;
            }
        }
    }

    /**
     * @return Collection<int, OrderDue>
     */
    public static function openDues(int $orderId): Collection
    {
        return OrderDue::query()->where('order_id', $orderId)->whereNull('removed_at')->orderBy('position')->orderBy('id')->get();
    }
}
