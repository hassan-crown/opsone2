<?php

/**
 * Randomized invariant over the whole P7 stock surface (ADR-0017): whatever sequence of receipts, adjustments, instant
 * transfers, ready-order confirmations (reservations), deliveries (issues), returns (receipt + inspection), and
 * partial / full cancellations runs — accepted or refused — every balance equals the sum of its movements, on hand
 * never goes below zero or below what is reserved, reservations never exceed what they hold, and a refused step
 * leaves the journal unchanged. The seed is printed on failure so a sequence can be replayed (STOCK_SEED=…).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M05ReadyOrder\T01ConfirmReadyOrder;
use App\Delivery\DeliveryProgress;
use App\Inventory\Quantity;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Returns\ReturnEligibility;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Returns/ReturnTestHelpers.php';
require_once __DIR__.'/../Cancellation/CancellationTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
});

function invariantHolds(string $context): void
{
    foreach (StockBalance::query()->get() as $b) {
        $movements = StockMovement::query()->where('location_id', $b->location_id)->where('stock_item_id', $b->stock_item_id);
        $onHand = (int) (clone $movements)->sum('on_hand_delta');
        $reserved = (int) (clone $movements)->sum('reserved_delta');
        expect($b->on_hand_milli)->toBe($onHand, "on hand ≠ journal after {$context}")
            ->and($b->reserved_milli)->toBe($reserved, "reserved ≠ journal after {$context}")
            ->and($b->on_hand_milli)->toBeGreaterThanOrEqual($b->reserved_milli, "on hand < reserved after {$context}")
            ->and($b->reserved_milli)->toBeGreaterThanOrEqual(0, "negative reserved after {$context}");
        $open = (int) StockReservation::query()->where('location_id', $b->location_id)->where('stock_item_id', $b->stock_item_id)->get()
            ->sum(fn (StockReservation $r) => $r->status->isOpen() ? $r->remainingMilli() : 0);
        expect($open)->toBe($b->reserved_milli, "open reservations ≠ reserved after {$context}");
    }
}

/**
 * Runs one random step; a refusal (409/422/stale) is allowed and must leave the journal as it was.
 */
function invariantStep(object $s, object $actors, int $op, array &$orders): string
{
    $variant = $s->f->variant->id;
    $qty = (string) mt_rand(1, 4);
    $movementsBefore = StockMovement::query()->count();
    $name = match ($op) {
        0 => 'receive', 1 => 'adjust', 2 => 'transfer', 3 => 'order', 4 => 'deliver', 5 => 'return', 6 => 'cancel', default => 'noop',
    };

    try {
        switch ($op) {
            case 0:
                stockReceive(mt_rand(0, 1) ? $s->la : $s->la2, 'ready_variant', $variant, $qty);
                break;
            case 1:
                $status = test()->actingAs($actors->owner)->postJson('/stock/adjustments', [
                    'location_id' => (mt_rand(0, 1) ? $s->la : $s->la2)->id, 'item_type' => 'ready_variant', 'item_id' => $variant,
                    'direction' => mt_rand(0, 1) ? 'in' : 'out', 'quantity' => $qty, 'reason' => 'جرد عشوائي',
                ])->status();
                expect($status)->toBeIn([201, 409, 422]);
                $refused = $status !== 201;
                break;
            case 2:
                [$from, $to] = mt_rand(0, 1) ? [$s->la, $s->la2] : [$s->la2, $s->la];
                $status = test()->actingAs($actors->owner)->postJson('/stock/transfers', [
                    'from_location_id' => $from->id, 'to_location_id' => $to->id, 'instant' => true,
                    'lines' => [['item_type' => 'ready_variant', 'item_id' => $variant, 'quantity' => $qty]],
                ])->status();
                expect($status)->toBeIn([201, 409, 422]);
                $refused = $status !== 201;
                break;
            case 3:
                $order = readyOrder($s, (int) $qty);
                $orders[] = $order->id;
                LockingTransaction::run(fn () => app(T01ConfirmReadyOrder::class)->handle($actors->owner, $order, (mt_rand(0, 1) ? $s->la : $s->la2)->id));
                break;
            case 4:
                $order = invariantPick($orders);
                $line = deliveryLineId($order);
                $deliverable = DeliveryProgress::lines($order)[$line]['deliverable'] ?? 0;
                $milli = $deliverable > 0 && mt_rand(0, 4) > 0 ? mt_rand(1, intdiv($deliverable, 1000)) * 1000 : Quantity::parse($qty);
                deliveryConfirm($actors->keeper, deliveryDraft($actors->keeper, $order, [$line => Quantity::format($milli)]));
                break;
            case 5:
                $order = invariantPick($orders);
                $line = deliveryLineId($order);
                $eligible = ReturnEligibility::lines($order)[$line]['eligible'] ?? 0;
                $units = $eligible >= 1000 ? mt_rand(1, intdiv($eligible, 1000)) : (int) $qty;
                $return = rtAccept($actors->manager, rtRequest($actors->keeper, $order, $line, (string) $units));
                $good = mt_rand(0, $units);
                $damaged = mt_rand(0, $units - $good);
                rtReceive($actors->keeper, $return, mt_rand(0, 1) ? $s->la : $s->la2, (string) $units, [(string) $good, (string) $damaged, (string) ($units - $good - $damaged)]);
                break;
            case 6:
                $order = invariantPick($orders);
                $scope = mt_rand(0, 2) === 0 ? ['scope' => 'full'] : ['scope' => 'partial', 'lines' => [['ref' => 'l'.deliveryLineId($order), 'quantity' => (string) mt_rand(1, 2)]]];
                cnCancel($actors->owner, $order, $scope);
                break;
        }
    } catch (InvalidTransition|ValidationException|StaleRecord|NoOrderYet) {
        $refused = true;
    }

    if ($refused ?? false) {
        expect(StockMovement::query()->count())->toBe($movementsBefore, "a refused {$name} left movements");
    }

    return $name.(($refused ?? false) ? ' (refused)' : '');
}

final class NoOrderYet extends RuntimeException {}

/**
 * @param  list<int>  $orders
 */
function invariantPick(array $orders): Order
{
    if ($orders === []) {
        throw new NoOrderYet;
    }

    return Order::query()->findOrFail($orders[mt_rand(0, count($orders) - 1)]);
}

it('keeps every balance equal to its journal and never negative over random P7 sequences', function (int $round) {
    $seed = (int) (getenv('STOCK_SEED') ?: random_int(1, PHP_INT_MAX >> 16)) + $round;
    mt_srand($seed);

    $f = returnFixture(20, 3, 1);
    $s = $f->s;
    $actors = (object) ['owner' => $f->owner, 'keeper' => $f->keeper, 'manager' => $f->manager];
    $orders = [$f->order->id];
    $trace = [];

    for ($i = 0; $i < 30; $i++) {
        $trace[] = invariantStep($s, $actors, mt_rand(0, 6), $orders);
        try {
            invariantHolds(implode(' → ', array_slice($trace, -5)));
        } catch (Throwable $e) {
            throw new RuntimeException("Stock invariant broken (STOCK_SEED={$seed}, step {$i}): ".implode(' → ', $trace)."\n".$e->getMessage(), 0, $e);
        }
    }

    if (getenv('STOCK_TRACE')) {
        fwrite(STDERR, "\n[seed {$seed}] ".implode(' → ', $trace)."\n");
    }
    // Some steps must have gone through, or the sequence proved nothing.
    expect(count(array_filter($trace, fn (string $t) => ! str_contains($t, 'refused'))))->toBeGreaterThan(10);
})->with([1, 2, 3, 4]);
