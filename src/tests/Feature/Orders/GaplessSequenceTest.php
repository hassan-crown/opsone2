<?php

/**
 * Order numbers per year without gaps or duplicates (ADR-0011): a rolled-back caller gives its number back, a new
 * year starts at 1, and concurrent processes queue on the counter row.
 */

use App\Support\GaplessSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('numbers 1..n per sequence and period, and a rollback gives the number back', function () {
    $seq = app(GaplessSequence::class);
    $taken = [];

    foreach (range(1, 6) as $i) {
        try {
            DB::transaction(function () use ($seq, $i, &$taken) {
                $n = $seq->next('orders', 2026);
                if ($i % 3 === 0) {
                    throw new RuntimeException('failed after taking a number');
                }
                $taken[] = $n;
            });
        } catch (RuntimeException) {
        }
    }

    expect($taken)->toBe([1, 2, 3, 4])
        ->and(DB::transaction(fn () => $seq->next('orders', 2027)))->toBe(1)
        ->and(DB::transaction(fn () => $seq->next('receipts', 2026)))->toBe(1);
});

it('has no gaps or duplicates when several processes take numbers at once', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available');
    }

    // A file database shared by the forked processes; IMMEDIATE transactions take the write lock at BEGIN, the
    // SQLite equivalent of the row lock MariaDB takes with SELECT ... FOR UPDATE.
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/sequence-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($file);
    Config::set('database.connections.seqtest', [
        'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
        'busy_timeout' => 20000, 'transaction_mode' => 'IMMEDIATE', 'journal_mode' => 'wal',
    ]);
    Schema::connection('seqtest')->create('sequence_counters', function ($table) {
        $table->id();
        $table->string('name', 40);
        $table->unsignedSmallInteger('period');
        $table->unsignedBigInteger('last_value')->default(0);
        $table->timestamps();
        $table->unique(['name', 'period']);
    });
    DB::purge('seqtest');

    $workers = 6;
    $perWorker = 15;
    $pids = [];
    foreach (range(1, $workers) as $w) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $default = Config::get('database.default');
            Config::set('database.default', 'seqtest');
            DB::purge('seqtest');
            $got = [];
            foreach (range(1, $perWorker) as $i) {
                try {
                    DB::transaction(function () use (&$got, $i, $w) {
                        $n = app(GaplessSequence::class)->next('orders', 2026);
                        usleep(random_int(0, 2000));
                        if (($i + $w) % 5 === 0) {
                            throw new RuntimeException('rollback');
                        }
                        $got[] = $n;
                    });
                } catch (RuntimeException) {
                }
            }
            file_put_contents($file.'.'.$w, implode(',', $got));
            Config::set('database.default', $default);
            posix_kill(getmypid(), SIGKILL); // leave without running the parent's shutdown handlers
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $all = [];
    foreach (range(1, $workers) as $w) {
        $content = (string) @file_get_contents($file.'.'.$w);
        $all = [...$all, ...($content === '' ? [] : array_map(intval(...), explode(',', $content)))];
        @unlink($file.'.'.$w);
    }
    sort($all);
    $last = (int) DB::connection('seqtest')->table('sequence_counters')->value('last_value');
    DB::purge('seqtest');
    foreach (glob($file.'*') ?: [] as $f) {
        @unlink($f);
    }

    expect(count($all))->toBeGreaterThan($workers * $perWorker / 2)
        ->and($all)->toBe(range(1, count($all)))
        ->and($last)->toBe(count($all));
});
