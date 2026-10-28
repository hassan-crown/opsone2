<?php

/**
 * Concurrent deliveries (INT-01, ADR-0018): real processes on a shared database. Two deliveries together above the
 * reservation can never both commit, a duplicated submit issues once, and each committed note keeps a gapless number.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M05ReadyOrder\T09CancelReadyRemainder;
use App\Models\Delivery\DeliveryNote;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/DeliveryTestHelpers.php';

/**
 * @param  Closure(): object  $setup
 * @param  list<Closure(object): string>  $workers
 * @param  Closure(object, list<string>): void  $check
 */
function deliveryRace(Closure $setup, array $workers, Closure $check): void
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available');
    }
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/delivery-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($file);
    $default = Config::get('database.default');
    $onServer = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $connection = $onServer ? $default : 'deliveryrace';
    if (! $onServer) {
        Config::set('database.connections.deliveryrace', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 30000, 'transaction_mode' => 'IMMEDIATE', 'journal_mode' => 'wal',
        ]);
        Config::set('database.default', 'deliveryrace');
    }
    DB::purge($connection);

    try {
        Artisan::call($onServer ? 'migrate:fresh' : 'migrate', ['--database' => $connection, '--force' => true]);
        Storage::fake('attachments');
        $state = $setup();
        DB::purge($connection);

        $pids = [];
        foreach ($workers as $i => $worker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge($connection);
                try {
                    $outcome = $worker($state);
                } catch (ValidationException) {
                    $outcome = 'refused';
                } catch (InvalidTransition $e) {
                    $outcome = 'state:'.$e->condition;
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

function confirmFromState(object $state, int $noteId): string
{
    $note = DeliveryNote::query()->findOrFail($noteId);

    return (string) deliveryConfirm(User::query()->findOrFail($state->keeper->id), $note)->number;
}

it('lets only one of two concurrent deliveries of 3 against 5 reserved commit', function () {
    deliveryRace(function () {
        $d = deliveryFixture(10, 5);
        $line = deliveryLineId($d->order);
        $d->a = deliveryDraft($d->keeper, $d->order, [$line => '3'])->id;
        $d->b = deliveryDraft($d->keeper, $d->order, [$line => '3'])->id;

        return $d;
    }, [
        fn (object $d) => confirmFromState($d, $d->a),
        fn (object $d) => confirmFromState($d, $d->b),
    ], function (object $d, array $outcomes) {
        $year = now()->year;
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'DN-')));
        expect($ok)->toBe(["DN-{$year}-000001"])
            ->and(array_values(array_diff($outcomes, $ok)))->toBe(['refused'])
            ->and(StockMovement::query()->where('kind', 'issue_reserved')->count())->toBe(1);
        $reservation = StockReservation::query()->firstOrFail();
        expect($reservation->issued_milli)->toBe(3000)->and($reservation->remainingMilli())->toBe(2000)
            ->and(Order::query()->find($d->order->id)->status)->toBe('partially_delivered')
            ->and(DeliveryNote::query()->where('status', 'confirmed')->count())->toBe(1);
        stockJournalMatches();
    });
});

it('keeps numbers gapless and the total at the reservation when four notes race for 5', function () {
    deliveryRace(function () {
        $d = deliveryFixture(10, 5);
        $line = deliveryLineId($d->order);
        $d->ids = [];
        foreach (['2', '2', '2', '2'] as $q) {
            $d->ids[] = deliveryDraft($d->keeper, $d->order, [$line => $q])->id;
        }

        return $d;
    }, array_map(fn (int $i) => fn (object $d) => confirmFromState($d, $d->ids[$i]), [0, 1, 2, 3]), function (object $d, array $outcomes) {
        $year = now()->year;
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'DN-')));
        sort($ok);
        expect($ok)->toBe(["DN-{$year}-000001", "DN-{$year}-000002"])
            ->and(StockReservation::query()->firstOrFail()->issued_milli)->toBe(4000)
            ->and(array_values(array_unique(array_diff($outcomes, $ok))))->toBe(['refused']);
        stockJournalMatches();
    });
});

it('issues once when the same note is confirmed by two processes at the same time', function () {
    deliveryRace(function () {
        $d = deliveryFixture(10, 5);
        $d->a = deliveryDraft($d->keeper, $d->order, [deliveryLineId($d->order) => '2'])->id;

        return $d;
    }, [
        fn (object $d) => confirmFromState($d, $d->a),
        fn (object $d) => confirmFromState($d, $d->a),
    ], function (object $d, array $outcomes) {
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'DN-')));
        expect($ok)->toHaveCount(1)
            ->and(array_values(array_diff($outcomes, $ok))[0])->toStartWith('state:not_from_confirmed')
            ->and(StockMovement::query()->where('kind', 'issue_reserved')->count())->toBe(1)
            ->and(StockReservation::query()->firstOrFail()->issued_milli)->toBe(2000);
        stockJournalMatches();
    });
});

it('serializes a delivery with a cancellation of the rest on the order row (scenario 4)', function () {
    deliveryRace(function () {
        $d = deliveryFixture(10, 5);
        $d->a = deliveryDraft($d->keeper, $d->order, [deliveryLineId($d->order) => '2'])->id;

        return $d;
    }, [
        fn (object $d) => confirmFromState($d, $d->a),
        fn (object $d) => 'cancelled:'.app(T09CancelReadyRemainder::class)->handle(User::query()->findOrFail($d->owner->id), Order::query()->findOrFail($d->order->id), 'إلغاء متزامن')->status,
    ], function (object $d, array $outcomes) {
        $reservation = StockReservation::query()->firstOrFail();
        $order = Order::query()->find($d->order->id);
        expect($reservation->issued_milli + $reservation->released_milli)->toBe(5000)->and($outcomes[1])->toStartWith('cancelled:');
        if (str_starts_with($outcomes[0], 'DN-')) {
            expect($reservation->issued_milli)->toBe(2000)->and($order->status)->toBe('delivered');
        } else {
            expect($outcomes[0])->toStartWith('state:')->and($reservation->issued_milli)->toBe(0)->and($order->status)->toBe('cancelled');
        }
        stockJournalMatches();
    });
});
