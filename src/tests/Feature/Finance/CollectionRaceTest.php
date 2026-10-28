<?php

/**
 * Concurrency of money writes (ADR-0014): two receipts for the same due, the same transfer reported by two channels at
 * once (INT-04), and gapless receipt numbers while some confirmations roll back (Q-41). Real processes race on a
 * shared database: MariaDB row locks when the suite points at a server, otherwise a file SQLite database whose
 * IMMEDIATE transactions take the write lock at BEGIN (the counterpart of SELECT … FOR UPDATE).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Finance\MoneyLedger;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentProof;
use App\Models\Finance\Receipt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/FinanceTestHelpers.php';

/**
 * Runs `$setup` on a fresh shared database, then each worker in its own process at the same time. Returns the
 * workers' outcomes (in order) and leaves the database for the assertions, which run in `$check`.
 *
 * @param  Closure(): object  $setup
 * @param  list<Closure(object): string>  $workers
 * @param  Closure(object, list<string>): void  $check
 */
function finRace(Closure $setup, array $workers, Closure $check): void
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available');
    }
    Storage::fake('attachments');
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/finance-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($file);
    $default = Config::get('database.default');
    $onServer = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $connection = $onServer ? $default : 'racetest';
    if (! $onServer) {
        Config::set('database.connections.racetest', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 30000, 'transaction_mode' => 'IMMEDIATE', 'journal_mode' => 'wal',
        ]);
        Config::set('database.default', 'racetest');
    }
    DB::purge($connection);

    try {
        Artisan::call($onServer ? 'migrate:fresh' : 'migrate', ['--database' => $connection, '--force' => true]);
        $state = $setup();
        DB::purge($connection);

        $pids = [];
        foreach ($workers as $i => $worker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge($connection);
                try {
                    $outcome = $worker($state);
                } catch (InvalidTransition $e) {
                    $outcome = $e->condition;
                } catch (Throwable $e) {
                    $outcome = 'error:'.$e->getMessage();
                }
                file_put_contents($file.'.'.$i, $outcome);
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::purge($connection);
        $outcomes = array_map(fn (int $i) => (string) @file_get_contents($file.'.'.$i), array_keys($workers));
        $check($state, $outcomes);
    } finally {
        if ($onServer) {
            Artisan::call('migrate:fresh', ['--database' => $connection, '--force' => true]);
        }
        DB::purge($connection);
        Config::set('database.default', $default);
        foreach (glob($file.'*') ?: [] as $f) {
            @unlink($f);
        }
    }
}

it('allocates two concurrent receipts for the same due exactly once: the due is filled, the rest goes on, nothing is counted twice', function () {
    finRace(function () {
        $f = financeFixture();
        finAgree($f, '15000');

        return $f;
    }, [
        fn (object $f) => finPay($f->finance, $f->order, '10000', ['reference' => 'FT-RACE-A'])->receipt?->number ?? 'none',
        fn (object $f) => finPay($f->finance, $f->order, '10000', ['reference' => 'FT-RACE-B'])->receipt?->number ?? 'none',
    ], function (object $f, array $outcomes) {
        $year = now()->format('Y');
        sort($outcomes);
        expect($outcomes)->toBe(["RC-{$year}-000001", "RC-{$year}-000002"])
            ->and(finDues($f->order))->toBe(['first' => ['amount' => 750_000, 'allocated' => 750_000], 'final' => ['amount' => 750_000, 'allocated' => 750_000]])
            ->and(MoneyLedger::credit($f->order->id)->minor)->toBe(500_000)
            ->and((int) MoneyEntry::query()->sum('amount_minor'))->toBe(2_000_000);
    });
});

it('INT-04: the same transfer recorded by two people at once becomes one payment and one receipt', function () {
    finRace(function () {
        $f = financeFixture();
        finAgree($f, '20000');

        return $f;
    }, [
        fn (object $f) => (string) finPay($f->finance, $f->order, '5000', ['reference' => 'FT 7788'])->id,
        fn (object $f) => (string) finPay($f->owner, $f->order, '5000', ['reference' => 'ft-7788'])->id,
    ], function (object $f, array $outcomes) {
        sort($outcomes);
        expect($outcomes[1])->toBe('duplicate_reference')
            ->and(Payment::query()->count())->toBe(1)
            ->and(Receipt::query()->count())->toBe(1)
            ->and(MoneyLedger::collected($f->order->id)->minor)->toBe(500_000);
    });
});

it('INT-04: staff recording and confirming the customer\'s receipt of the same transfer at once give one receipt', function () {
    finRace(function () {
        $f = financeFixture();
        finAgree($f, '20000');
        $f->proof = finProof($f->order, '5000', 'FT-9911');

        return $f;
    }, [
        fn (object $f) => 'confirmed:'.finConfirm($f->finance, PaymentProof::query()->findOrFail($f->proof->id))->payment_id,
        fn (object $f) => 'recorded:'.finPay($f->owner, $f->order, '5000', ['reference' => 'FT-9911'])->id,
    ], function (object $f, array $outcomes) {
        expect($outcomes[0])->toStartWith('confirmed:')
            ->and($outcomes[1])->toBeIn(['matching_proof', 'duplicate_reference'])
            ->and(Payment::query()->count())->toBe(1)
            ->and(Receipt::query()->count())->toBe(1)
            ->and(MoneyLedger::collected($f->order->id)->minor)->toBe(500_000);
    });
});

it('Q-41: receipt numbers stay gapless and unique while several processes confirm and some roll back', function () {
    $workers = [];
    foreach (range(1, 4) as $w) {
        $workers[] = function (object $f) use ($w) {
            $taken = [];
            $fail = false;
            Receipt::created(function () use (&$fail) {
                if ($fail) {
                    throw new RuntimeException('rollback after numbering');
                }
            });
            foreach (range(1, 4) as $i) {
                $fail = ($i + $w) % 3 === 0;
                try {
                    $taken[] = finPay($f->finance, $f->order, (string) (100 * $w + $i), ['reference' => "FT-G-{$w}-{$i}"])->receipt?->number;
                } catch (RuntimeException) {
                }
            }

            return implode(',', $taken);
        };
    }

    finRace(function () {
        $f = financeFixture();
        finAgree($f, '100000');

        return $f;
    }, $workers, function (object $f, array $outcomes) {
        $numbers = Receipt::query()->orderBy('number')->pluck('number')->all();
        $year = now()->format('Y');
        $expected = array_map(fn (int $n) => sprintf('RC-%s-%06d', $year, $n), range(1, count($numbers)));
        expect($numbers)->toBe($expected)
            ->and(count($numbers))->toBe(Payment::query()->count())
            ->and(count($numbers))->toBeGreaterThan(8)
            ->and(collect($outcomes)->flatMap(fn (string $o) => array_filter(explode(',', $o)))->sort()->values()->all())->toBe($expected);
    });
});
