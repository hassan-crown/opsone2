<?php

/**
 * Cancelling a ready order (M-05-T09 through M-34, FUL-02/FUL-12): only what is reserved and not issued is released;
 * what was delivered stays delivered (scenario «تسليم2 من5 ثم إلغاء الباقي»).
 */

use App\Delivery\DeliveryProgress;
use App\Models\Inventory\StockReservation;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/CancellationTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
});

it('releases only the cut quantity of a ready line and keeps the order confirmed', function () {
    $d = deliveryFixture(10, 5);
    $line = deliveryLineId($d->order);
    $total = $d->order->total_minor;
    $scope = ['scope' => 'partial', 'lines' => [['ref' => 'l'.$line, 'quantity' => '2']]];
    $preview = cnPreview($d->order, $scope);

    cnCancel($d->owner, $d->order, $scope);
    $order = $d->order->refresh();
    $reservation = StockReservation::query()->sole();

    expect($order->status)->toBe('confirmed')
        ->and($reservation->released_milli)->toBe(2000)->and($reservation->status->value)->toBe('active')
        ->and($reservation->remainingMilli())->toBe(3000)
        ->and(DeliveryProgress::lines($order)[$line]['deliverable'])->toBe(3000)
        ->and($order->total_minor)->toBe($preview['settlement']['money']['value_after_minor'])
        ->and($order->total_minor)->toBeLessThan($total)
        ->and(array_column($preview['settlement']['stock'], 'release_milli'))->toBe([2000])
        ->and(cnItems($order))->toBe(['stock_release' => 'done'])
        ->and(cnFollowUp($order)?->status)->toBe('closed');
    stockJournalMatches();

    // The rest is delivered later: the reservation ends «settled mixed».
    deliveryConfirm($d->keeper, deliveryDraft($d->keeper, $order, [$line => '3']));
    expect($order->refresh()->status)->toBe('delivered')->and(StockReservation::query()->sole()->status->value)->toBe('settled_mixed');
});

it('keeps 2 delivered of 5 and releases only the 3 not issued on a full cancellation', function () {
    $d = deliveryFixture(10, 5);
    $line = deliveryLineId($d->order);
    deliveryConfirm($d->keeper, deliveryDraft($d->keeper, $d->order, [$line => '2']));

    $preview = cnPreview($d->order, ['scope' => 'full']);
    expect($preview['stays']['delivered'][0]['delivered_milli'])->toBe(2000)
        ->and($preview['order']['status_after'])->toBe('delivered');
    cnCancel($d->owner, $d->order, ['scope' => 'full']);

    $reservation = StockReservation::query()->sole();
    expect($d->order->refresh()->status)->toBe('delivered')
        ->and($reservation->issued_milli)->toBe(2000)->and($reservation->released_milli)->toBe(3000)
        ->and($reservation->status->value)->toBe('settled_mixed')
        ->and(StateTransition::query()->where('machine', 'M-05')->where('transition', 'T09')->count())->toBe(1);
    stockJournalMatches();
});

it('cancels a confirmed ready order with nothing delivered', function () {
    $d = deliveryFixture(10, 5);
    cnCancel($d->owner, $d->order, ['scope' => 'full']);

    expect($d->order->refresh()->status)->toBe('cancelled')
        ->and(StockReservation::query()->sole()->status->value)->toBe('released');
    stockJournalMatches();
});

it('refuses a cut above the reservation and a partial that cancels everything', function () {
    $d = deliveryFixture(10, 5);
    $line = deliveryLineId($d->order);

    cnRefused(fn () => cnCancel($d->owner, $d->order, ['scope' => 'partial', 'lines' => [['ref' => 'l'.$line, 'quantity' => '6']]]), ValidationException::class);
    cnRefused(fn () => cnCancel($d->owner, $d->order, ['scope' => 'partial', 'lines' => [['ref' => 'l'.$line, 'quantity' => '5']]]), ValidationException::class);
});

it('fingerprints what stays with the customer, so an issue or a delivery after the review makes it stale', function () {
    $d = deliveryFixture(10, 5);
    $impact = cnPreview($d->order, ['scope' => 'full']);
    $hash = App\Cancellation\CancellationImpact::hash($impact);

    $issued = $impact;
    $issued['stays']['issued'][] = ['number' => 'SI-2026-000009', 'item' => 'قماش', 'unit' => 'metre', 'issued_milli' => 1500];
    $changed = $impact;
    $changed['stops']['change'] = ['number' => 'CH-1', 'status' => 'open'];
    expect(App\Cancellation\CancellationImpact::hash($issued))->not->toBe($hash)
        ->and(App\Cancellation\CancellationImpact::hash($changed))->not->toBe($hash);

    // A real delivery between the review and the confirm: 409 preview_stale, nothing applied.
    deliveryConfirm($d->keeper, deliveryDraft($d->keeper, $d->order, [deliveryLineId($d->order) => '1']));
    cnRefused(fn () => cnCancel($d->owner, $d->order, ['scope' => 'full'], hash: $impact['hash']), App\Access\Exceptions\InvalidTransition::class, 'preview_stale');
});
