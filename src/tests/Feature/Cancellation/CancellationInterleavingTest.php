<?php

/**
 * The two orders of commits of a cancellation and a delivery on the same reserved quantity, pinned without processes so
 * they run on every database (CI uses SQLite in memory, where the forked race in CancellationRaceTest may land on
 * either side). The loser is refused with its exact condition and nothing is applied twice.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Models\Inventory\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/CancellationTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
});

function interleaved(Closure $attempt): string
{
    try {
        return 'ok:'.$attempt();
    } catch (InvalidTransition $e) {
        return 'state:'.$e->condition;
    }
}

it('refuses by state a reviewed cancellation after everything was delivered', function () {
    $d = deliveryFixture(10, 5);
    $note = deliveryDraft($d->keeper, $d->order, [deliveryLineId($d->order) => '5']);
    $hash = cnPreview($d->order, ['scope' => 'full'])['hash'];

    expect(interleaved(fn () => deliveryConfirm($d->keeper, $note)->number))->toStartWith('ok:DN-')
        ->and(interleaved(fn () => cnCancel($d->owner, $d->order, ['scope' => 'full'], hash: $hash)->number))->toBe('state:not_from_delivered')
        ->and(StockReservation::query()->sole()->issued_milli)->toBe(5000)
        ->and(StockReservation::query()->sole()->released_milli)->toBe(0);
    stockJournalMatches();
});

it('makes a reviewed cancellation stale after a partial delivery', function () {
    $d = deliveryFixture(10, 5);
    $note = deliveryDraft($d->keeper, $d->order, [deliveryLineId($d->order) => '2']);
    $hash = cnPreview($d->order, ['scope' => 'full'])['hash'];

    expect(interleaved(fn () => deliveryConfirm($d->keeper, $note)->number))->toStartWith('ok:DN-')
        ->and(interleaved(fn () => cnCancel($d->owner, $d->order, ['scope' => 'full'], hash: $hash)->number))->toBe('state:preview_stale')
        ->and(StockReservation::query()->sole()->released_milli)->toBe(0);
    stockJournalMatches();
});

it('refuses the delivery once the cancellation committed first', function () {
    $d = deliveryFixture(10, 5);
    $note = deliveryDraft($d->keeper, $d->order, [deliveryLineId($d->order) => '5']);

    expect(interleaved(fn () => cnCancel($d->owner, $d->order, ['scope' => 'full'])->number))->toStartWith('ok:CN-')
        ->and(interleaved(fn () => deliveryConfirm($d->keeper, $note)->number))->toBe('state:order_cancelled')
        ->and(StockReservation::query()->sole()->issued_milli)->toBe(0)
        ->and(StockReservation::query()->sole()->released_milli)->toBe(5000);
    stockJournalMatches();
});
