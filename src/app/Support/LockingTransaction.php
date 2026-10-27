<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * A transaction for read-lock-write work on hot rows (gapless counters). On MariaDB 11.8 the default
 * REPEATABLE READ with `innodb_snapshot_isolation` refuses to lock a row changed after the transaction's first read
 * (error 1020), so this one transaction runs at READ COMMITTED, where a locking read always sees the latest row
 * and queues on its lock. Concurrency errors (deadlock, 1020) are retried. Inside an outer transaction the outer
 * one's level applies; callers that number inside their own transaction must start it here.
 */
final class LockingTransaction
{
    public const ATTEMPTS = 3;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @param  int<1, max>  $attempts
     * @return T
     */
    public static function run(Closure $work, int $attempts = self::ATTEMPTS): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Applies to the next transaction only.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction($work, $attempts);
    }
}
