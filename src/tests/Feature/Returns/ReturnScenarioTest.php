<?php

/**
 * Integration scenarios 7 and 8 (SRS «اختبارات ترابط ملزمة», P04) end to end on the real delivery and stock: the return
 * parts with their document numbers (DN → RT, SR/RT on the journal), eligibility held at acceptance, only good goods
 * back to available, damaged in custody, and no refund or commission effect from the stock side.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Enums\ReturnStatus;
use App\Models\Inventory\StockMovement;
use App\Actions\StateMachines\M15Return\T03WithdrawReturn;
use App\Models\OrderLine;
use App\Models\Returns\OrderReturnLine;
use App\Returns\ReturnEligibility;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ReturnTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('attachments'));

it('scenario 7: two approved returns of a delivered 2 never accept more than 2 and a receipt is not subtracted twice', function () {
    $f = returnFixture(10, 2, 2);
    $year = now()->year;

    // Requests hold nothing: both may be raised for the whole delivered quantity.
    $a = rtRequest($f->keeper, $f->order, $f->line, '2');
    $b = rtRequest($f->manager, $f->order, $f->line, '2');
    expect([$a->number, $b->number])->toBe(["RT-{$year}-000001", "RT-{$year}-000002"])
        ->and(ReturnEligibility::lines($f->order->refresh())[$f->line]['eligible'])->toBe(2000);

    // The first acceptance holds the delivered 2; the second is refused without effect.
    rtAccept($f->manager, $a);
    expect(ReturnEligibility::lines($f->order)[$f->line]['eligible'])->toBe(0);
    rtRefused(fn () => rtAccept($f->manager, $b), InvalidTransition::class, 'over_delivered');

    // Receiving 1 of A keeps eligibility at 0 (received is part of accepted, never subtracted again).
    rtReceive($f->keeper, $a, $f->location, '1');
    expect(ReturnEligibility::lines($f->order)[$f->line])->toMatchArray(['delivered' => 2000, 'held' => 2000, 'eligible' => 0]);

    // Withdrawing A's unreceived remainder frees exactly 1: B may now accept 1, not 2.
    app(T03WithdrawReturn::class)->handle($f->manager, $a->refresh(), 'العميل احتفظ بقطعة', $a->lock_version, rtKey());
    expect($a->refresh()->status)->toBe(ReturnStatus::Received)
        ->and(OrderReturnLine::query()->where('order_return_id', $a->id)->value('accepted_milli'))->toBe(1000)
        ->and(ReturnEligibility::lines($f->order)[$f->line]['eligible'])->toBe(1000);
    rtRefused(fn () => rtAccept($f->manager, $b, '2'), InvalidTransition::class, 'over_delivered');
    rtAccept($f->manager, $b, '1');
    expect($b->refresh()->status)->toBe(ReturnStatus::Accepted)
        ->and(ReturnEligibility::lines($f->order)[$f->line]['eligible'])->toBe(0);
});

it('scenario 8: a partial return of good and damaged goods adds only the good to available and refunds nothing', function () {
    $f = returnFixture(10, 5, 5);
    $year = now()->year;
    expect(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id))->toBe(['on_hand' => '5', 'reserved' => '0', 'available' => '5']);

    $return = rtRequest($f->keeper, $f->order, $f->line, '3');
    $line = OrderLine::query()->findOrFail($f->line);
    rtAccept($f->manager, $return);
    expect($return->refresh()->value_minor)->toBe(ReturnEligibility::valueOf($line, 3000))
        ->and($return->settlement_minor)->toBe($return->value_minor)
        // Accepted, not received: stock does not move.
        ->and(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id)['on_hand'])->toBe('5');

    // Receipt alone: nothing becomes available before inspection (FUL-06).
    rtReceive($f->keeper, $return, $f->location, '3');
    expect($return->refresh()->status)->toBe(ReturnStatus::Received)
        ->and(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id)['available'])->toBe('5');

    rtInspect($f->keeper, $return, '2', '1');
    expect($return->refresh()->status)->toBe(ReturnStatus::Inspected)
        ->and(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id))->toBe(['on_hand' => '7', 'reserved' => '0', 'available' => '7']);

    $moves = StockMovement::query()->where('source_type', 'order_return_part')->orderBy('id')->get();
    expect($moves->map(fn ($m) => [$m->kind->value, $m->quantity_milli, $m->on_hand_delta, $m->document_number])->all())->toBe([
        ['return_in', 2000, 2000, "RT-{$year}-000001"],
        ['return_damaged', 1000, 0, "RT-{$year}-000001"],
    ])->and(StockMovement::query()->where('kind', 'issue_reserved')->value('document_number'))->toBe("SR-{$year}-000001")
        ->and(DB::table('delivery_notes')->value('number'))->toBe("DN-{$year}-000001")
        // The money decision is independent: no refund, no commission entry from the stock side.
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(DB::table('commission_entries')->count())->toBe(0)
        ->and($return->refund_id)->toBeNull()
        ->and(StateTransition::query()->where('machine', 'M-15')->where('subject_id', $return->id)->orderBy('id')->pluck('transition')->all())
        ->toBe(['T00', 'T05', 'T01', 'T02']);
    stockJournalMatches();
});

it('records receipt and inspection in one step: both transitions kept, stock moved once, a replay changes nothing', function () {
    $f = returnFixture(10, 5, 5);
    $return = rtRequest($f->keeper, $f->order, $f->line, '2');
    rtAccept($f->manager, $return);

    $key = rtKey();
    $version = $return->refresh()->lock_version;
    rtReceive($f->keeper, $return, $f->location, '2', ['1', '0', '1'], $key, $version);
    $before = rtSnapshot();
    rtReceive($f->keeper, $return, $f->location, '2', ['1', '0', '1'], $key, $version);

    expect(rtSnapshot())->toBe($before)
        ->and($return->refresh()->status)->toBe(ReturnStatus::Inspected)
        ->and(StateTransition::query()->where('machine', 'M-15')->where('subject_id', $return->id)->orderBy('id')->pluck('transition')->all())->toBe(['T00', 'T05', 'T01', 'T02'])
        ->and(StockMovement::query()->where('source_type', 'order_return_part')->pluck('kind')->map->value->all())->toBe(['return_in'])
        ->and(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id)['available'])->toBe('6');
});

it('each inspection outcome produces its own stock effect', function (string $good, string $damaged, string $rejected, array $kinds, string $available) {
    $f = returnFixture(10, 5, 5);
    $return = rtRequest($f->keeper, $f->order, $f->line, '1');
    rtAccept($f->manager, $return);
    rtReceive($f->keeper, $return, $f->location, '1');
    rtInspect($f->keeper, $return, $good, $damaged, $rejected);

    expect(StockMovement::query()->where('source_type', 'order_return_part')->pluck('kind')->map->value->all())->toBe($kinds)
        ->and(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id)['available'])->toBe($available);
    stockJournalMatches();
})->with([
    'good → back to the location' => ['1', '0', '0', ['return_in'], '6'],
    'damaged → custody, never available' => ['0', '1', '0', ['return_damaged'], '5'],
    'rejected → handed back, no movement' => ['0', '0', '1', [], '5'],
]);

it('a partial receipt is a part of its own (T07) and the return is inspected only when everything accepted was', function () {
    $f = returnFixture(10, 5, 5);
    $return = rtRequest($f->keeper, $f->order, $f->line, '3');
    rtAccept($f->manager, $return);

    rtReceive($f->keeper, $return, $f->location, '1', ['1', '0', '0']);
    expect($return->refresh()->status)->toBe(ReturnStatus::Received);
    rtReceive($f->keeper, $return, $f->location, '2');
    expect($return->refresh()->status)->toBe(ReturnStatus::Received);
    rtInspect($f->keeper, $return, '1', '1');

    expect($return->refresh()->status)->toBe(ReturnStatus::Inspected)
        ->and(StateTransition::query()->where('machine', 'M-15')->where('subject_id', $return->id)->orderBy('id')->pluck('transition')->all())
        ->toBe(['T00', 'T05', 'T01', 'T02', 'T07', 'T02'])
        ->and(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id)['available'])->toBe('7');
});
