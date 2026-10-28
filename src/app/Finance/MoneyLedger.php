<?php

namespace App\Finance;

use App\Enums\JournalKind;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Payment;
use App\Models\User;
use App\Money\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The append-only money journal (ADR-0014). A payment's money sits on orders, either as the order's credit (no due)
 * or allocated to a due; every effect is a journal of signed entries and nothing is ever updated or deleted. The
 * invariants hold on every write: moves sum to zero, a collection puts exactly the payment's amount on orders, no
 * place of a payment ever goes negative, and no due is allocated above its amount.
 *
 * `reverse()` / `reversePayment()` are the only correction primitives (docs/p6-finance-api.md); `refund()` records
 * money that really left (6c, ADR-0015).
 * Call inside the transaction that locked the orders (FinanceLock).
 */
final class MoneyLedger
{
    /**
     * @param  array<int, Money>  $shares  order id → part of the payment; sums to the payment
     */
    public function collect(Payment $payment, array $shares, ?User $actor, string $source): MoneyJournal
    {
        $entries = [];
        foreach ($shares as $orderId => $amount) {
            if ($amount->isNegative() || $amount->isZero()) {
                throw new LogicException('A collection share is positive.');
            }
            $entries[] = ['order_id' => $orderId, 'due_id' => null, 'amount' => $amount->minor];
        }

        return $this->write(JournalKind::Collection, $payment, $entries, $actor, $source)
            ?? throw new LogicException('A collection has entries.');
    }

    /**
     * @param  list<array{order_id: int, due_id: int|null, amount: int}>  $entries
     */
    public function write(JournalKind $kind, Payment $payment, array $entries, ?User $actor, string $source, ?string $reason = null, ?MoneyJournal $reverses = null): ?MoneyJournal
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Money journals are written inside the transaction that locked the orders.');
        }
        $entries = array_values(array_filter($entries, fn (array $e) => $e['amount'] !== 0));
        if ($entries === []) {
            return null;
        }

        $sum = array_sum(array_column($entries, 'amount'));
        $expected = match ($kind) {
            JournalKind::Collection => $payment->amount_minor,
            JournalKind::Reversal => $reverses === null ? throw new LogicException('A reversal names its journal.')
                : -(int) MoneyEntry::query()->where('journal_id', $reverses->id)->sum('amount_minor'),
            // Money that left: only from the order's credit (deallocate first), never as a positive entry.
            JournalKind::Refund => $sum < 0 && array_filter($entries, fn (array $e) => $e['due_id'] !== null || $e['amount'] > 0) === []
                ? $sum : throw new LogicException('A refund journal takes money off order credit only.'),
            default => 0,
        };
        if ($sum !== $expected) {
            throw new LogicException("A {$kind->value} journal must sum to {$expected}, not {$sum}.");
        }

        $now = now();
        $journal = MoneyJournal::query()->create([
            'kind' => $kind,
            'payment_id' => $payment->id,
            'reverses_journal_id' => $reverses?->id,
            'reason' => $reason,
            'actor_user_id' => $actor?->id,
            'source' => $source,
            'created_at' => $now,
        ]);
        foreach ($entries as $entry) {
            MoneyEntry::query()->create([
                'journal_id' => $journal->id,
                'payment_id' => $payment->id,
                'order_id' => $entry['order_id'],
                'order_due_id' => $entry['due_id'],
                'amount_minor' => $entry['amount'],
                'created_at' => $now,
            ]);
        }

        $this->guard($payment, $entries, $kind);

        return $journal;
    }

    /**
     * Money that really left to the customer (an executed refund, M-12-T02/T05): the amount leaves the order's credit
     * of this payment. The caller deallocates first (`RefundPosting`); the guard refuses a credit going negative.
     */
    public function refund(Payment $payment, int $orderId, Money $amount, ?User $actor, string $source, string $reason): MoneyJournal
    {
        if ($amount->isNegative() || $amount->isZero()) {
            throw new LogicException('A refund is positive.');
        }

        return $this->write(JournalKind::Refund, $payment, [['order_id' => $orderId, 'due_id' => null, 'amount' => -$amount->minor]], $actor, $source, $reason)
            ?? throw new LogicException('A refund has entries.');
    }

    /**
     * Cancels one journal by its exact negation, once (`reverses_journal_id` is unique). The 6c correction primitive:
     * a correction reverses a payment's journals; a refund reverses its latest allocations first.
     */
    public function reverse(MoneyJournal $journal, string $reason, ?User $actor, string $source): MoneyJournal
    {
        if ($journal->kind === JournalKind::Reversal) {
            throw new LogicException('A reversal is not reversed; write the effect again instead.');
        }
        if (MoneyJournal::query()->where('reverses_journal_id', $journal->id)->exists()) {
            throw new LogicException('This journal was already reversed.');
        }

        $entries = MoneyEntry::query()->where('journal_id', $journal->id)->get()
            ->map(fn (MoneyEntry $e) => ['order_id' => $e->order_id, 'due_id' => $e->order_due_id, 'amount' => -$e->amount_minor])
            ->all();

        return $this->write(JournalKind::Reversal, $journal->payment, array_values($entries), $actor, $source, $reason, $journal)
            ?? throw new LogicException('A journal has entries.');
    }

    /**
     * Takes every effect of a payment back, newest first (6c: M-11-T07 correction of an entry error). Afterwards the
     * payment contributes nothing to any order or due; its receipt stays as the record of what was issued.
     *
     * @return list<MoneyJournal>
     */
    public function reversePayment(Payment $payment, string $reason, ?User $actor, string $source): array
    {
        $reversed = MoneyJournal::query()->whereNotNull('reverses_journal_id')->where('payment_id', $payment->id)->pluck('reverses_journal_id')->all();
        $open = MoneyJournal::query()->where('payment_id', $payment->id)->where('kind', '!=', JournalKind::Reversal->value)
            ->whereNotIn('id', $reversed)->orderByDesc('id')->get();

        $journals = [];
        foreach ($open as $journal) {
            $journals[] = $this->reverse($journal, $reason, $actor, $source);
        }

        return $journals;
    }

    /**
     * Net collected on the order: every confirmed payment's money that sits on it, after reversals
     * (CollectedAmounts, SRS: «صافي المقبوض»). A proof under review is never here.
     */
    public static function collected(int $orderId): Money
    {
        return Money::of((int) MoneyEntry::query()->where('order_id', $orderId)->sum('amount_minor'));
    }

    /**
     * Money on the order that no due holds («مقدم غير مخصص»).
     */
    public static function credit(int $orderId): Money
    {
        return Money::of((int) MoneyEntry::query()->where('order_id', $orderId)->whereNull('order_due_id')->sum('amount_minor'));
    }

    /**
     * The order's credit per payment, oldest payment first, positive balances only.
     *
     * @return array<int, int> payment id → halalas
     */
    public static function creditByPayment(int $orderId): array
    {
        return MoneyEntry::query()->join('payments', 'payments.id', '=', 'money_entries.payment_id')
            ->where('money_entries.order_id', $orderId)->whereNull('money_entries.order_due_id')
            ->groupBy('money_entries.payment_id', 'payments.paid_on')
            ->havingRaw('SUM(money_entries.amount_minor) > 0')
            ->orderBy('payments.paid_on')->orderBy('money_entries.payment_id')
            ->selectRaw('money_entries.payment_id as payment_id, SUM(money_entries.amount_minor) as balance')
            ->get()->mapWithKeys(fn ($row) => [(int) $row->getAttribute('payment_id') => (int) $row->getAttribute('balance')])->all();
    }

    /**
     * @return array<int, int> due id → allocated halalas
     */
    public static function allocatedByDue(int $orderId): array
    {
        return MoneyEntry::query()->where('order_id', $orderId)->whereNotNull('order_due_id')
            ->groupBy('order_due_id')->selectRaw('order_due_id, SUM(amount_minor) as allocated')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->getAttribute('order_due_id') => (int) $row->getAttribute('allocated')])->all();
    }

    /**
     * Where a payment's money is on one order: due id (0 = credit) → halalas, non-zero only.
     *
     * @return array<int, int>
     */
    public static function placesOf(int $paymentId, int $orderId): array
    {
        return MoneyEntry::query()->where('payment_id', $paymentId)->where('order_id', $orderId)
            ->groupBy('order_due_id')->selectRaw('order_due_id, SUM(amount_minor) as balance')->get()
            ->mapWithKeys(fn ($row) => [(int) ($row->getAttribute('order_due_id') ?? 0) => (int) $row->getAttribute('balance')])
            ->filter(fn (int $v) => $v !== 0)->all();
    }

    /**
     * A payment's share per order (what it put there, after transfers and reversals).
     *
     * @return array<int, int> order id → halalas
     */
    public static function sharesOf(int $paymentId): array
    {
        return MoneyEntry::query()->where('payment_id', $paymentId)->groupBy('order_id')
            ->selectRaw('order_id, SUM(amount_minor) as share')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->getAttribute('order_id') => (int) $row->getAttribute('share')])
            ->filter(fn (int $v) => $v !== 0)->all();
    }

    /**
     * No place of this payment goes negative, and no due is allocated above its amount (an allocation never makes
     * the same money count twice). A violation is a programming error: the transaction rolls back.
     *
     * @param  list<array{order_id: int, due_id: int|null, amount: int}>  $entries
     */
    private function guard(Payment $payment, array $entries, JournalKind $kind): void
    {
        foreach ($entries as $entry) {
            $balance = (int) MoneyEntry::query()->where('payment_id', $payment->id)->where('order_id', $entry['order_id'])
                ->where('order_due_id', $entry['due_id'])->sum('amount_minor');
            if ($balance < 0) {
                throw new LogicException('A payment place would go negative.');
            }
            if ($entry['due_id'] !== null && $entry['amount'] > 0 && $kind !== JournalKind::Reversal) {
                $due = OrderDue::query()->findOrFail($entry['due_id']);
                $allocated = (int) MoneyEntry::query()->where('order_due_id', $due->id)->sum('amount_minor');
                if ($due->order_id !== $entry['order_id'] || $due->removed_at !== null || $allocated > $due->amount_minor) {
                    throw new LogicException('A due cannot hold more than its amount.');
                }
            }
        }
    }
}
