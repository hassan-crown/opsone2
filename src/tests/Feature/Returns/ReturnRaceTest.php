<?php

/**
 * Concurrent returns (INT-03, scenario 7; scenario 4 return side), real processes on a shared database: acceptances of
 * two returns together above the delivered quantity never both commit; one receipt submitted twice posts stock once;
 * an acceptance racing a cancellation of the undelivered remainder only ever holds what was actually delivered.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M05ReadyOrder\T09CancelReadyRemainder;
use App\Actions\StateMachines\M15Return\T01ReceiveReturn;
use App\Actions\StateMachines\M15Return\T05AcceptReturn;
use App\Inventory\Quantity;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnLine;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ReturnTestHelpers.php';

/**
 * @param  Closure(): object  $setup
 * @param  list<Closure(object): string>  $workers
 * @param  Closure(object, list<string>): void  $check
 */
function returnRace(Closure $setup, array $workers, Closure $check): void
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available');
    }
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/return-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($file);
    $default = Config::get('database.default');
    $onServer = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $connection = $onServer ? $default : 'returnrace';
    if (! $onServer) {
        Config::set('database.connections.returnrace', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 30000, 'transaction_mode' => 'IMMEDIATE', 'journal_mode' => 'wal',
        ]);
        Config::set('database.default', 'returnrace');
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

function acceptFromState(object $state, int $returnId): string
{
    $return = OrderReturn::query()->findOrFail($returnId);

    return app(T05AcceptReturn::class)->handle(User::query()->findOrFail($state->manager->id), $return, null, 'مقبول', null, null, null)->status->value;
}

it('accepts only one of two concurrent returns of 2 against a delivered 2', function () {
    returnRace(function () {
        $f = returnFixture(10, 2, 2);
        $f->a = rtRequest($f->keeper, $f->order, $f->line, '2')->id;
        $f->b = rtRequest($f->keeper, $f->order, $f->line, '2')->id;

        return $f;
    }, [
        fn (object $f) => acceptFromState($f, $f->a),
        fn (object $f) => acceptFromState($f, $f->b),
    ], function (object $f, array $outcomes) {
        sort($outcomes);
        expect($outcomes)->toBe(['accepted', 'state:over_delivered'])
            ->and((int) OrderReturnLine::query()->sum('accepted_milli'))->toBe(2000)
            ->and(OrderReturn::query()->where('status', 'accepted')->count())->toBe(1);
    });
});

it('posts stock once when the same receipt with inspection is submitted twice at the same time', function () {
    returnRace(function () {
        $f = returnFixture(10, 5, 5);
        $r = rtRequest($f->keeper, $f->order, $f->line, '2');
        rtAccept($f->manager, $r);
        $f->r = $r->id;
        $f->version = $r->refresh()->lock_version;
        $f->key = rtKey();

        return $f;
    }, array_fill(0, 2, function (object $f) {
        $return = OrderReturn::query()->findOrFail($f->r);
        $line = ['order_line_id' => $f->line, 'quantity_milli' => 2000, 'good_milli' => 2000, 'damaged_milli' => 0, 'rejected_milli' => 0];

        return app(T01ReceiveReturn::class)->handle(User::query()->findOrFail($f->keeper->id), $return, $f->location->id, [$line], true, null, $f->version, $f->key)->status->value;
    }), function (object $f, array $outcomes) {
        expect($outcomes)->each->toBe('inspected')
            ->and(StockMovement::query()->where('kind', 'return_in')->count())->toBe(1)
            ->and(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id)['on_hand'])->toBe('7');
        stockJournalMatches();
    });
});

it('serializes an acceptance with a cancellation of the undelivered rest: only the delivered is ever held (scenario 4)', function () {
    returnRace(function () {
        $f = returnFixture(10, 5, 2);
        $f->a = rtRequest($f->keeper, $f->order, $f->line, '2')->id;

        return $f;
    }, [
        fn (object $f) => acceptFromState($f, $f->a),
        fn (object $f) => 'cancelled:'.app(T09CancelReadyRemainder::class)->handle(User::query()->findOrFail($f->owner->id), Order::query()->findOrFail($f->order->id), 'إلغاء الباقي')->status,
    ], function (object $f, array $outcomes) {
        $reservation = StockReservation::query()->firstOrFail();
        expect($outcomes)->toBe(['accepted', 'cancelled:delivered'])
            ->and($reservation->issued_milli)->toBe(2000)->and($reservation->released_milli)->toBe(3000)
            ->and((int) OrderReturnLine::query()->sum('accepted_milli'))->toBe(2000)
            ->and(StockMovement::query()->where('source_type', 'order_return_part')->count())->toBe(0);
        stockJournalMatches();
    });
});
