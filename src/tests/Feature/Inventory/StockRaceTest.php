<?php

/**
 * Concurrency of stock writes (ADR-0017, INT-01): real processes on a shared database (MariaDB row locks on a server,
 * an IMMEDIATE-transaction SQLite file otherwise). Concurrent issues never make a balance negative, two
 * confirmations never both take the last unit (scenario 3), a duplicated receipt counts once (FUL-03), and a
 * cancellation racing a delivery keeps exactly one consistent outcome (scenario 4).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\IssueReadyReservation;
use App\Actions\StateMachines\M05ReadyOrder\T01ConfirmReadyOrder;
use App\Actions\StateMachines\M05ReadyOrder\T09CancelReadyRemainder;
use App\Inventory\Contracts\StockReceiver;
use App\Inventory\Quantity;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/InventoryTestHelpers.php';

/**
 * @param  Closure(): object  $setup
 * @param  list<Closure(object): string>  $workers
 * @param  Closure(object, list<string>): void  $check
 */
function stockRace(Closure $setup, array $workers, Closure $check): void
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available');
    }
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/stock-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($file);
    $default = Config::get('database.default');
    $onServer = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $connection = $onServer ? $default : 'stockrace';
    if (! $onServer) {
        Config::set('database.connections.stockrace', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 30000, 'transaction_mode' => 'IMMEDIATE', 'journal_mode' => 'wal',
        ]);
        Config::set('database.default', 'stockrace');
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

it('never goes negative when six processes take 1 each from 3 on hand', function () {
    stockRace(function () {
        $s = stockFixture();
        stockReceive($s->la, 'material', $s->material->id, '3');

        return $s;
    }, array_fill(0, 6, fn (object $s) => (string) app(AdjustStock::class)->handle(
        User::query()->findOrFail($s->keeper->id), StockLocation::query()->findOrFail($s->la->id), 'material', $s->material->id, 'out', 1000, 'صرف متزامن',
    )->document_number), function (object $s, array $outcomes) {
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'SA-')));
        expect($ok)->toHaveCount(3)
            ->and(array_values(array_diff($outcomes, $ok)))->toBe(['refused', 'refused', 'refused'])
            ->and(stockBalance($s->la, 'material', $s->material->id)['on_hand'])->toBe('0');
        sort($ok);
        $year = now()->year;
        expect($ok)->toBe(["SA-{$year}-000001", "SA-{$year}-000002", "SA-{$year}-000003"]);
        stockJournalMatches();
    });
});

it('lets exactly one of two concurrent confirmations reserve the last unit (scenario 3)', function () {
    stockRace(function () {
        $s = stockFixture();
        stockReceive($s->la, 'ready_variant', $s->f->variant->id, '1');
        $s->first = readyOrder($s, 1);
        $s->second = readyOrder($s, 1);

        return $s;
    }, [
        fn (object $s) => 'ok:'.app(T01ConfirmReadyOrder::class)->handle(User::query()->findOrFail($s->f->org->owner->id), Order::query()->findOrFail($s->first->id), $s->la->id)->number,
        fn (object $s) => 'ok:'.app(T01ConfirmReadyOrder::class)->handle(User::query()->findOrFail($s->f->org->owner->id), Order::query()->findOrFail($s->second->id), $s->la->id)->number,
    ], function (object $s, array $outcomes) {
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'ok:')));
        expect($ok)->toHaveCount(1)
            ->and(array_values(array_diff($outcomes, $ok)))->toBe(['refused'])
            ->and(StockReservation::query()->count())->toBe(1)
            ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0'])
            ->and(Order::query()->where('status', 'confirmed')->count())->toBe(1);
        stockJournalMatches();
    });
});

it('counts a receipt delivered four times at once only once (FUL-03)', function () {
    stockRace(fn () => stockFixture(), array_fill(0, 4, function (object $s) {
        LockingTransaction::run(fn () => app(StockReceiver::class)->receive($s->la->id, 'material', $s->material->id, 4000, 'purchase_receipt', 7, null, 'po-7:line-1'));

        return 'ok';
    }), function (object $s, array $outcomes) {
        expect(array_filter($outcomes, fn (string $o) => $o !== 'ok'))->toBe([])
            ->and(stockBalance($s->la, 'material', $s->material->id)['on_hand'])->toBe('4')
            ->and(StockMovement::query()->where('kind', 'receipt')->count())->toBe(1);
        stockJournalMatches();
    });
});

it('keeps one consistent outcome when a cancellation races a delivery (scenario 4)', function () {
    stockRace(function () {
        $s = stockFixture();
        stockReceive($s->la, 'ready_variant', $s->f->variant->id, '3');
        $s->order = readyOrder($s, 2);
        LockingTransaction::run(fn () => app(T01ConfirmReadyOrder::class)->handle($s->f->org->owner, $s->order, $s->la->id));
        $s->reservation = StockReservation::query()->firstOrFail();

        return $s;
    }, [
        fn (object $s) => LockingTransaction::run(fn () => 'issued:'.app(IssueReadyReservation::class)->handle(
            User::query()->findOrFail($s->f->org->owner->id), Order::query()->findOrFail($s->order->id), StockReservation::query()->findOrFail($s->reservation->id),
            Quantity::parse('1'), 'delivery', 1, 'delivery-1',
        )->issued_milli),
        fn (object $s) => 'cancelled:'.app(T09CancelReadyRemainder::class)->handle(User::query()->findOrFail($s->f->org->owner->id), Order::query()->findOrFail($s->order->id), 'إلغاء متزامن')->status,
    ], function (object $s, array $outcomes) {
        $reservation = StockReservation::query()->findOrFail($s->reservation->id);
        $order = Order::query()->findOrFail($s->order->id);
        $balance = stockBalance($s->la, 'ready_variant', $s->f->variant->id);

        expect($reservation->issued_milli + $reservation->released_milli)->toBe(2000)
            ->and($balance['reserved'])->toBe('0')
            ->and($outcomes[1])->toStartWith('cancelled:');
        if ($outcomes[0] === 'issued:1000') {
            // The delivery committed first: it stays, the cancellation released only the rest.
            expect($order->status)->toBe('delivered')->and($balance['on_hand'])->toBe('2')->and($reservation->status->value)->toBe('settled_mixed');
        } else {
            // The cancellation committed first: the late delivery is refused and nothing left stock.
            expect($outcomes[0])->toStartWith('state:')->and($order->status)->toBe('cancelled')
                ->and($balance['on_hand'])->toBe('3')->and($reservation->status->value)->toBe('released');
        }
        expect(StockMovement::query()->whereIn('kind', ['receipt', 'adjustment_in', 'transfer_in'])->count())->toBe(1);
        stockJournalMatches();
    });
});
