<?php

namespace App\Finance;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\OrderKind;
use App\Models\Finance\DebtWriteOff;
use App\Models\Finance\DebtWriteOffEntry;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderClosure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side of debt write-offs (P-103, ADR-0034). A write-off journal credits the receivable of each due it settles and
 * debits `bad_debt` by the same total; a reversal negates it. «Written off» on a due is minus the sum of its
 * receivable entries, so a reversed write-off counts zero. Every reader of what is still owed subtracts it: closure
 * blockers, open dues (work list, reminders, aging), the order and portal money cards and the allocator.
 */
final class DebtWriteOffs
{
    public static function any(int $orderId): bool
    {
        return DebtWriteOff::query()->where('order_id', $orderId)->exists();
    }

    /** Net amount written off on the order (halalas, ≥ 0). */
    public static function writtenOff(int $orderId): int
    {
        return -(int) DebtWriteOffEntry::query()->where('order_id', $orderId)->where('account', DebtWriteOffEntry::RECEIVABLE)->sum('amount_minor');
    }

    /**
     * @param  array<int, mixed>  $orderIds
     * @return array<int, int> due id → net written off
     */
    public static function byDue(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DebtWriteOffEntry::query()->whereIn('order_id', $orderIds)->where('account', DebtWriteOffEntry::RECEIVABLE)
            ->whereNotNull('order_due_id')->groupBy('order_due_id')
            ->selectRaw('order_due_id, SUM(amount_minor) as total')->pluck('total', 'order_due_id')
            ->map(fn ($v) => -(int) $v)->filter(fn (int $v) => $v !== 0)->all();
    }

    /**
     * What is still owed per open due: amount − allocated − written off.
     *
     * @return array<int, int> due id → halalas (> 0 only)
     */
    public static function owedByDue(Order $order): array
    {
        $allocated = MoneyLedger::allocatedByDue($order->id);
        $written = self::byDue([$order->id]);
        $owed = [];
        foreach (Allocator::openDues($order->id) as $due) {
            $left = $due->amount_minor - ($allocated[$due->id] ?? 0) - ($written[$due->id] ?? 0);
            if ($left > 0) {
                $owed[$due->id] = $left;
            }
        }

        return $owed;
    }

    /** Write-offs whose reversal does not exist yet. */
    public static function reversible(DebtWriteOff $writeOff): bool
    {
        return $writeOff->kind === DebtWriteOff::WRITE_OFF
            && ! DebtWriteOff::query()->where('reverses_id', $writeOff->id)->exists();
    }

    /**
     * The write-off card on the order page: what may be written off now and the history. Amounts only with P-37.
     *
     * @return array<string, mixed>|null
     */
    public static function panel(Order $order, User $viewer): ?array
    {
        if ($order->kind !== OrderKind::Custom) {
            return null;
        }
        $amounts = Access::can($viewer, Permission::P37, $order);
        $may = Access::can($viewer, Permission::P103, $order);
        $owed = array_sum(self::owedByDue($order));
        $rows = DebtWriteOff::query()->where('order_id', $order->id)->with(['actor:id,name', 'attachments'])->orderBy('id')->get();
        if ($rows->isEmpty() && ! ($may && $owed > 0 && OrderClosure::closable($order))) {
            return null;
        }
        $reversed = $rows->pluck('reverses_id')->filter()->all();

        return [
            'owed_minor' => $amounts ? $owed : null,
            'can_write_off' => $may && $amounts && $owed > 0 && OrderClosure::closable($order),
            'lock_version' => FinanceLock::versionOf($order),
            'entries' => $rows->map(fn (DebtWriteOff $w) => [
                'id' => $w->id, 'number' => $w->number, 'kind' => $w->kind, 'reason' => $w->reason,
                'amount_minor' => $amounts ? $w->amount_minor : null, 'by' => $w->actor?->name, 'at' => $w->created_at->toIso8601String(),
                'reverses_id' => $w->reverses_id, 'files' => $w->attachments->count(),
                'can_reverse' => $may && $w->kind === DebtWriteOff::WRITE_OFF && ! in_array($w->id, $reversed, true),
            ])->values()->all(),
        ];
    }

    /**
     * Net written off in [start, end] on the given orders (write-offs minus reversals, by the journal's time) — the
     * report figure next to collections.
     *
     * @param  Builder|list<int>  $orderIds
     */
    public static function inRange(mixed $orderIds, string $from, string $to): int
    {
        return (int) DB::table('debt_write_off_entries')->join('debt_write_offs', 'debt_write_offs.id', '=', 'debt_write_off_entries.debt_write_off_id')
            ->whereIn('debt_write_off_entries.order_id', $orderIds)->where('debt_write_off_entries.account', DebtWriteOffEntry::BAD_DEBT)
            ->whereBetween('debt_write_offs.created_at', [$from, $to])
            ->sum('debt_write_off_entries.amount_minor');
    }

    /** Invariant check (tests, diagnostics): every write-off journal of the order sums to zero. */
    public static function balanced(int $orderId): bool
    {
        return ! DebtWriteOffEntry::query()->where('order_id', $orderId)->groupBy('debt_write_off_id')
            ->havingRaw('SUM(amount_minor) <> 0')->selectRaw('debt_write_off_id')->exists();
    }
}
