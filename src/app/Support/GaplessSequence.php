<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gapless numbers per sequence and period (orders per year now, receipts later). The counter row is locked by an
 * atomic upsert inside the caller's transaction, so concurrent callers queue on it and a rolled-back caller gives its
 * number back: committed numbers are 1..n without holes or duplicates. Start that transaction with
 * LockingTransaction::run() (READ COMMITTED on MariaDB). One deployment serves one facility (ADR-0002), so the
 * facility is implicit in the sequence name.
 */
final class GaplessSequence
{
    public function next(string $name, int $period): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A gapless number must be taken inside the transaction that uses it.');
        }

        $now = now();
        // One atomic upsert: creates the period's row at 1 or increments it, taking the row's exclusive lock at once
        // (no shared-then-exclusive upgrade, which deadlocks under concurrency). Concurrent callers queue on that lock
        // until this transaction ends.
        DB::table('sequence_counters')->upsert(
            [['name' => $name, 'period' => $period, 'last_value' => 1, 'created_at' => $now, 'updated_at' => $now]],
            ['name', 'period'],
            ['last_value' => DB::raw('last_value + 1'), 'updated_at' => $now],
        );

        // A locking read sees the value this transaction just wrote, whatever snapshot the caller already holds.
        $next = DB::table('sequence_counters')->where('name', $name)->where('period', $period)->lockForUpdate()->value('last_value');
        if ($next === null) {
            throw new LogicException("Sequence {$name}/{$period} is missing.");
        }
        $next = (int) $next;

        return $next;
    }

    /**
     * `PREFIX-YYYY-00042` for the current year.
     */
    public function format(string $name, string $prefix, int $pad = 5): string
    {
        $year = (int) now()->format('Y');

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $this->next($name, $year), $pad, '0', STR_PAD_LEFT));
    }
}
