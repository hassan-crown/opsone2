<?php

/**
 * A cancellation racing a delivery on the same quantity (INT-01, P03 «سباق مع تسليم»; ADR-0020): real processes on a
 * shared database. Both lock the order row first; whichever commits first wins and the other is refused — the
 * delivery because the order is cancelled, the cancellation because its reviewed impact no longer holds. Issued plus
 * released never exceeds the reservation, and two concurrent decisions apply once.
 *
 * Which process wins depends on the scheduler (locally the cancellation usually does, on CI the delivery may), so
 * each order of commits is also pinned without processes in CancellationInterleavingTest.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M34Cancellation\T03RecordAndAcceptCancellation;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Delivery\DeliveryNote;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/CancellationTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';

/**
 * @param  Closure(): object  $setup
 * @param  list<Closure(object): string>  $workers
 * @param  Closure(object, list<string>): void  $check
 */
function cancellationRace(Closure $setup, array $workers, Closure $check): void
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available');
    }
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/cancellation-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($file);
    $default = Config::get('database.default');
    $onServer = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $connection = $onServer ? $default : 'cancelrace';
    if (! $onServer) {
        Config::set('database.connections.cancelrace', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 30000, 'transaction_mode' => 'IMMEDIATE', 'journal_mode' => 'wal',
        ]);
        Config::set('database.default', 'cancelrace');
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

function cancelFromState(object $state, string $hash, ?string $key = null): string
{
    $order = Order::query()->findOrFail($state->order->id);

    return 'cancelled:'.app(T03RecordAndAcceptCancellation::class)->handle(User::query()->findOrFail($state->owner->id), $order,
        [...cnRequest(), 'scope' => 'full'], $hash, null, $key ?? (string) Str::uuid())->number;
}

it('never lets a cancellation and a delivery both succeed on the same reserved quantity', function () {
    cancellationRace(function () {
        $d = deliveryFixture(10, 5);
        $d->note = deliveryDraft($d->keeper, $d->order, [deliveryLineId($d->order) => '5'])->id;
        $d->hash = cnPreview($d->order, ['scope' => 'full'])['hash'];

        return $d;
    }, [
        fn (object $d) => (string) deliveryConfirm(User::query()->findOrFail($d->keeper->id), DeliveryNote::query()->findOrFail($d->note))->number,
        fn (object $d) => cancelFromState($d, $d->hash),
    ], function (object $d, array $outcomes) {
        $reservation = StockReservation::query()->sole();
        $order = Order::query()->findOrFail($d->order->id);
        $delivered = str_starts_with($outcomes[0], 'DN-');
        $cancelled = str_starts_with($outcomes[1], 'cancelled:');
        expect($delivered xor $cancelled)->toBeTrue()
            ->and($reservation->issued_milli + $reservation->released_milli)->toBe(5000);
        if ($delivered) {
            // All 5 were handed over: the order is delivered, so the cancellation is refused by state before its review
            // is even compared (a partial delivery would make it `preview_stale` instead — see the interleaving test).
            expect($outcomes[1])->toBe('state:not_from_delivered')->and($order->status)->toBe('delivered')
                ->and(OrderCancellation::query()->count())->toBe(0);
        } else {
            expect($outcomes[0])->toBe('state:order_cancelled')->and($order->status)->toBe('cancelled')
                ->and($reservation->issued_milli)->toBe(0);
        }
        stockJournalMatches();
    });
});

it('applies one of two concurrent decisions on the same order', function () {
    cancellationRace(function () {
        $d = deliveryFixture(10, 5);
        $d->hash = cnPreview($d->order, ['scope' => 'full'])['hash'];

        return $d;
    }, [
        fn (object $d) => cancelFromState($d, $d->hash),
        fn (object $d) => cancelFromState($d, $d->hash),
    ], function (object $d, array $outcomes) {
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'cancelled:')));
        expect($ok)->toHaveCount(1)
            ->and(OrderCancellation::query()->count())->toBe(1)
            ->and(StockReservation::query()->sole()->released_milli)->toBe(5000)
            ->and(DB::table('stock_movements')->where('kind', 'release')->count())->toBe(1);
        stockJournalMatches();
    });
});
