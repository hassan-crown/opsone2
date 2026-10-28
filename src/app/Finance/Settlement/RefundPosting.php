<?php

namespace App\Finance\Settlement;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\JournalKind;
use App\Enums\StateMachine;
use App\Finance\Allocator;
use App\Finance\CollectionConditions;
use App\Finance\FinanceLock;
use App\Finance\MoneyLedger;
use App\Models\Finance\OrderDue;
use App\Models\Finance\OrderFinance;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place where a refund's money leaves (M-12 → executed): the payment's credit on the order is used first,
 * then its latest allocations go back to credit (newest due first, a deallocation journal), then one refund journal
 * takes the amount off; the voucher number is taken in the same transaction. Other credit is re-allocated and the
 * stage collection checks re-evaluated — a completed stage is never un-completed (shortfalls are listed instead).
 * Call under the order lock.
 */
final class RefundPosting
{
    public function __construct(
        private readonly MoneyLedger $ledger,
        private readonly Allocator $allocator,
        private readonly CollectionConditions $conditions,
        private readonly VoucherNumbers $numbers,
    ) {}

    public function execute(Refund $refund, Order $order, OrderFinance $head, string $code, ?User $actor, string $source): string
    {
        $places = MoneyLedger::placesOf($refund->payment_id, $order->id);
        if (array_sum($places) < $refund->amount_minor) {
            // The payment's money moved away (transfer or correction) since the request: nothing leaves.
            throw new InvalidTransition(StateMachine::M12, $code, 'not_refundable');
        }

        $need = $refund->amount_minor - max(0, $places[0] ?? 0);
        if ($need > 0) {
            $positions = OrderDue::query()->whereIn('id', array_keys(array_diff_key($places, [0 => true])))->pluck('position', 'id')->all();
            uksort($positions, fn (int $a, int $b) => [$positions[$b], $b] <=> [$positions[$a], $a]);
            $entries = [];
            foreach (array_keys($positions) as $dueId) {
                if ($need === 0) {
                    break;
                }
                $take = min($places[$dueId], $need);
                if ($take > 0) {
                    $entries[] = ['order_id' => $order->id, 'due_id' => $dueId, 'amount' => -$take];
                    $need -= $take;
                }
            }
            $moved = -array_sum(array_column($entries, 'amount'));
            $entries[] = ['order_id' => $order->id, 'due_id' => null, 'amount' => $moved];
            $this->ledger->write(JournalKind::Deallocation, $refund->payment, $entries, $actor, $source, 'refund:'.$refund->id);
        }

        $journal = $this->ledger->refund($refund->payment, $order->id, $refund->amount(), $actor, $source, 'refund:'.$refund->id);
        $number = $refund->number ?? $this->numbers->refund();
        $refund->forceFill(['number' => $number, 'journal_id' => $journal->id, 'executed_by' => $actor?->id, 'executed_at' => now()]);

        $this->allocator->settle($order, $actor, $source);
        $this->conditions->evaluate($order, $refund->payment_id, $actor);
        FinanceLock::touch($head);

        $event = new RefundExecuted($refund->id, $order->id, $refund->payment_id, $refund->amount_minor, $number);
        DB::afterCommit(fn () => event($event));

        return $number;
    }
}
