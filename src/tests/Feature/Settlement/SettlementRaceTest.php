<?php

/**
 * Concurrency of settlement writes (ADR-0015): two refunds of one payment at once can never exceed its refundable
 * limit (scenario 9), and refund / correction voucher numbers stay gapless while some executions roll back (Q-41).
 * Same harness as the collection races: real processes on a shared database (MariaDB row locks on a server, an
 * IMMEDIATE-transaction SQLite file otherwise).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Finance\MoneyLedger;
use App\Finance\Settlement\RefundLimits;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/SettlementTestHelpers.php';

/**
 * Runs `$setup` on a fresh shared database, then each worker in its own process at the same time. Returns the
 * workers' outcomes (in order) and leaves the database for the assertions, which run in `$check`.
 *
 * @param  Closure(): object  $setup
 * @param  list<Closure(object): string>  $workers
 * @param  Closure(object, list<string>): void  $check
 */
function setRace(Closure $setup, array $workers, Closure $check): void
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available');
    }
    Storage::fake('attachments');
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/settlement-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
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

it('scenario 9: two refunds of 300 and 800 requested at once from 1,000 refundable — exactly one is reserved, the other refused', function () {
    setRace(function () {
        $f = financeFixture();
        finAgree($f, '1000');
        $f->payment = finPay($f->finance, $f->order, '1000');

        return $f;
    }, [
        fn (object $f) => 'ok:'.setRefund($f->finance, $f->order, Payment::query()->findOrFail($f->payment->id), '300', null, false)->id,
        fn (object $f) => 'ok:'.setRefund($f->owner, $f->order, Payment::query()->findOrFail($f->payment->id), '800', null, false)->id,
    ], function (object $f, array $outcomes) {
        // No version check here: the limit read under the order lock alone decides.
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'ok:')));
        expect($ok)->toHaveCount(1)
            ->and(array_values(array_diff($outcomes, $ok)))->toBe(['not_refundable'])
            ->and(Refund::query()->count())->toBe(1)
            ->and(RefundLimits::reserved($f->payment->id, $f->order->id)->minor)->toBeLessThanOrEqual(100_000);
    });
});

it('two refunds of one payment executed at the same time each leave once, with consecutive vouchers and the payment emptied exactly', function () {
    setRace(function () {
        $f = financeFixture();
        finAgree($f, '1000');
        $f->payment = finPay($f->finance, $f->order, '1000');
        $f->a = setRefund($f->finance, $f->order, $f->payment, '600');
        $f->b = setRefund($f->owner, $f->order, $f->payment, '400');

        return $f;
    }, [
        fn (object $f) => (string) setPaid($f->finance, Refund::query()->findOrFail($f->a->id), 'TRX-A')->number,
        fn (object $f) => (string) setPaid($f->owner, Refund::query()->findOrFail($f->b->id), 'TRX-B')->number,
    ], function (object $f, array $outcomes) {
        $year = now()->format('Y');
        sort($outcomes);
        expect($outcomes)->toBe(["RF-{$year}-000001", "RF-{$year}-000002"])
            ->and(MoneyLedger::collected($f->order->id)->minor)->toBe(0)
            ->and(MoneyJournal::query()->where('kind', 'refund')->count())->toBe(2);
    });
});

it('Q-41: refund voucher numbers stay gapless and unique while several processes execute and some roll back', function () {
    $workers = [];
    foreach (range(1, 3) as $w) {
        $workers[] = function (object $f) use ($w) {
            $taken = [];
            $fail = false;
            MoneyJournal::created(function (MoneyJournal $j) use (&$fail) {
                if ($fail && $j->kind->value === 'refund') {
                    throw new RuntimeException('rollback after numbering');
                }
            });
            foreach ($f->refunds[$w] as $i => $id) {
                $fail = ($i + $w) % 3 === 0;
                try {
                    $taken[] = setPaid($f->finance, Refund::query()->findOrFail($id), "TRX-{$w}-{$i}")->number;
                } catch (RuntimeException) {
                }
            }

            return implode(',', array_filter($taken));
        };
    }

    setRace(function () {
        $f = financeFixture();
        finAgree($f, '10000');
        $payment = finPay($f->finance, $f->order, '10000');
        $f->refunds = [];
        foreach (range(1, 3) as $w) {
            foreach (range(1, 4) as $i) {
                $f->refunds[$w][$i] = setRefund($f->finance, $f->order, $payment, (string) (10 * $w + $i))->id;
            }
        }

        return $f;
    }, $workers, function (object $f, array $outcomes) {
        $numbers = Refund::query()->whereNotNull('number')->orderBy('number')->pluck('number')->all();
        $year = now()->format('Y');
        $expected = array_map(fn (int $n) => sprintf('RF-%s-%06d', $year, $n), range(1, count($numbers)));
        expect($numbers)->toBe($expected)
            ->and(count($numbers))->toBeGreaterThan(5)
            ->and(count($numbers))->toBe(MoneyJournal::query()->where('kind', 'refund')->count())
            ->and(collect($outcomes)->flatMap(fn (string $o) => array_filter(explode(',', $o)))->sort()->values()->all())->toBe($expected);
    });
});
