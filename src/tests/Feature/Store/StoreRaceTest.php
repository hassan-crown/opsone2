<?php

/**
 * Store concurrency on real processes (MariaDB row locks on a server, an IMMEDIATE-transaction SQLite file otherwise):
 * two buyers starting a checkout for the last unit — one reserves, the other is refused before any money (scenario 3,
 * WEB-06); two checkouts racing for a coupon's last use — one holds it (SAL-07, M-32-T01 under the coupon lock).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Models\Customer;
use App\Models\Inventory\StockReservation;
use App\Models\Store\Coupon;
use App\Models\Store\CouponUsage;
use App\Models\Store\StoreCheckout;
use App\Store\CouponAdmin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/StoreTestHelpers.php';

/**
 * @param  Closure(): object  $setup
 * @param  list<Closure(object): string>  $workers
 * @param  Closure(object, list<string>): void  $check
 */
function storeRace(Closure $setup, array $workers, Closure $check): void
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available');
    }
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0755, true);
    $file = $dir.'/store-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($file);
    $default = Config::get('database.default');
    $onServer = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $connection = $onServer ? $default : 'storerace';
    if (! $onServer) {
        Config::set('database.connections.storerace', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 30000, 'transaction_mode' => 'IMMEDIATE', 'journal_mode' => 'wal',
        ]);
        Config::set('database.default', 'storerace');
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

it('scenario 3: two buyers checking out the last unit at once — exactly one reserves it, nothing is oversold', function () {
    storeRace(function () {
        $s = storeFixture(1);
        $s->buyers = [storeBuyer()->id, storeBuyer()->id];

        return $s;
    }, [
        fn (object $s) => 'ok:'.storeStart(Customer::query()->findOrFail($s->buyers[0]), [$s->f->variant->id => 1])['checkout']->number,
        fn (object $s) => 'ok:'.storeStart(Customer::query()->findOrFail($s->buyers[1]), [$s->f->variant->id => 1])['checkout']->number,
    ], function (object $s, array $outcomes) {
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'ok:')));
        expect($ok)->toHaveCount(1)
            ->and(array_values(array_diff($outcomes, $ok)))->toBe(['refused'])
            ->and(StoreCheckout::query()->count())->toBe(1)
            ->and(StockReservation::query()->count())->toBe(1)
            ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0']);
        stockJournalMatches();
    });
});

it('SAL-07: two checkouts racing for a coupon\'s last use — one holds it, the other is refused', function () {
    storeRace(function () {
        $s = storeFixture(5);
        app(CouponAdmin::class)->save($s->f->org->owner, [
            'code' => 'LAST1', 'title_ar' => 'آخر استخدام', 'kind' => 'amount', 'value' => '5', 'starts_at' => now()->subHour()->toDateTimeString(), 'max_uses' => 1,
        ]);
        $s->buyers = [storeBuyer()->id, storeBuyer()->id];

        return $s;
    }, [
        fn (object $s) => 'ok:'.storeStart(Customer::query()->findOrFail($s->buyers[0]), [$s->f->variant->id => 1], 'LAST1')['checkout']->number,
        fn (object $s) => 'ok:'.storeStart(Customer::query()->findOrFail($s->buyers[1]), [$s->f->variant->id => 1], 'LAST1')['checkout']->number,
    ], function (object $s, array $outcomes) {
        $ok = array_values(array_filter($outcomes, fn (string $o) => str_starts_with($o, 'ok:')));
        expect($ok)->toHaveCount(1)
            ->and(array_values(array_diff($outcomes, $ok)))->toBe(['refused'])
            ->and(Coupon::query()->sole()->uses_count)->toBe(1)
            ->and(CouponUsage::query()->count())->toBe(1)
            // the refused attempt rolled back whole: no order, no reservation, no payment left behind
            ->and(StoreCheckout::query()->count())->toBe(1)
            ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id)['reserved'])->toBe('1');
    });
});
