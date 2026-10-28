<?php

/**
 * SHR-03: two concurrent confirmations of the same member and time — exactly one books it (policy «block»).
 */

use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Models\Appointment;
use App\Workflow\AppointmentConflict;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/WorkflowTestHelpers.php';

it('lets only one of two concurrent confirmations book the same member and time', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available');
    }

    // On MariaDB (the suite pointed at a server test database) the real membership row locks are exercised. Otherwise
    // the whole app runs on a file database shared by the forked processes, whose IMMEDIATE transactions take the write
    // lock at BEGIN — the SQLite counterpart of SELECT ... FOR UPDATE.
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/appointments-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($file);
    $default = Config::get('database.default');
    $onServer = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $connection = $onServer ? $default : 'racetest';
    if (! $onServer) {
        Config::set('database.connections.racetest', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 20000, 'transaction_mode' => 'IMMEDIATE', 'journal_mode' => 'wal',
        ]);
        Config::set('database.default', 'racetest');
    }
    DB::purge($connection);

    try {
        Artisan::call($onServer ? 'migrate:fresh' : 'migrate', ['--database' => $connection, '--force' => true]);
        $f = workflowFixture();
        blockConflicts($f->org->owner);
        $tech = $f->org->member('executor', [$f->org->a]);
        $ids = [
            propose($f->sales, $f->order, [$tech->id])->id,
            propose($f->sales, $f->order, [$tech->id], '2026-11-03 10:00', '2026-11-03 12:00')->id,
        ];
        DB::purge($connection);

        $pids = [];
        foreach ($ids as $i => $id) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge($connection);
                $outcome = 'ok';
                try {
                    app(T02ConfirmAppointment::class)->handle($f->sales, Appointment::query()->findOrFail($id), 'phone');
                } catch (AppointmentConflict $e) {
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

        $outcomes = [(string) @file_get_contents($file.'.0'), (string) @file_get_contents($file.'.1')];
        sort($outcomes);
        DB::purge($connection);

        expect($outcomes)->toBe(['conflict_blocked', 'ok'])
            ->and(Appointment::query()->where('status', 'confirmed')->count())->toBe(1);
    } finally {
        if ($onServer) {
            // Committed rows would leak into later tests of this process: start them from an empty schema again.
            Artisan::call('migrate:fresh', ['--database' => $connection, '--force' => true]);
        }
        DB::purge($connection);
        Config::set('database.default', $default);
        foreach (glob($file.'*') ?: [] as $f) {
            @unlink($f);
        }
    }
});
