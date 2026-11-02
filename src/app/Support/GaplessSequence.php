<?php

namespace App\Support;

use App\Models\Branch;
use App\Numbering\NumberFormats;
use App\Workflow\AppointmentBooking;
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
    public function next(string $name, int $period, ?int $startAt = null): int
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
        if ($startAt !== null && $next < $startAt) {
            // A configured starting number (ADR-0037): the counter jumps once, still under this transaction's lock.
            DB::table('sequence_counters')->where('name', $name)->where('period', $period)->update(['last_value' => $startAt, 'updated_at' => $now]);
            $next = $startAt;
        }

        return $next;
    }

    /**
     * `PREFIX-YYYY-00042` for the current year — or the facility's format for this sequence (ADR-0037): its prefix,
     * Gregorian/Hijri/no year, branch segment with its own counter, digits and starting number.
     */
    public function format(string $name, string $prefix, int $pad = 5, ?int $branchId = null): string
    {
        $format = NumberFormats::find($name);
        if ($format === null) {
            $year = (int) now()->format('Y');

            return NumberFormats::compose($prefix, null, $year, $this->next($name, $year), $pad);
        }

        $now = now(AppointmentBooking::timezone());
        $period = NumberFormats::period($format->year_mode, $now);
        $code = null;
        $counter = $name;
        $branch = $format->per_branch && $branchId !== null ? Branch::query()->find($branchId) : null;
        if ($branch !== null) {
            $code = $branch->code !== null && $branch->code !== '' ? strtoupper($branch->code) : 'B'.$branch->id;
            $counter = "{$name}@{$branch->id}";
        }
        $start = $format->start_at !== null && $format->start_period === $period && $counter === $name ? $format->start_at : null;

        return NumberFormats::compose($format->prefix, $code, $format->year_mode === 'none' ? null : $period, $this->next($counter, $period, $start), $format->pad);
    }
}
