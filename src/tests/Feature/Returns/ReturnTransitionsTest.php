<?php

/**
 * Every M-15 transition allowed for its grant and refused — wrong state, wrong grant, stale version, invalid quantity —
 * without any side effect (no row, movement, number, transition, audit, notification or idempotency key).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M15Return\T03WithdrawReturn;
use App\Actions\StateMachines\M15Return\T04CorrectInspection;
use App\Actions\StateMachines\M15Return\T05AcceptReturn;
use App\Actions\StateMachines\M15Return\T06RejectReturn;
use App\Enums\ReturnStatus;
use App\Inventory\Quantity;
use App\Models\Inventory\StockMovement;
use App\Models\Returns\OrderReturnPart;
use App\Models\StateTransition;
use App\Support\StaleRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ReturnTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = returnFixture(10, 5, 3);
    $this->executor = $this->f->org->member('executor', [$this->f->org->a]);
});

function rtWithdraw($actor, $return, ?int $version = null)
{
    $return->refresh();

    return app(T03WithdrawReturn::class)->handle($actor, $return, 'العميل عدل عن الإرجاع', $version ?? $return->lock_version, rtKey());
}

function rtCorrect($actor, $return, int $partId, string $good)
{
    $return->refresh();

    return app(T04CorrectInspection::class)->handle($actor, $return, $partId, Quantity::parse($good), 'خطأ في التصنيف', $return->lock_version, rtKey());
}

it('T00 request: P-53 or P-42 on delivered quantities only', function () {
    $f = $this->f;
    expect(rtRequest($f->keeper, $f->order, $f->line, '3')->status)->toBe(ReturnStatus::Requested)
        ->and(rtRequest($f->manager, $f->order, $f->line, '1')->status)->toBe(ReturnStatus::Requested)
        ->and(DB::table('user_notifications')->where('type', 'return.requested')->count())->toBeGreaterThan(0);

    rtRefused(fn () => rtRequest($this->executor, $f->order, $f->line, '1'), AuthorizationException::class);
    rtRefused(fn () => rtRequest($f->keeper, $f->order, $f->line, '4'), ValidationException::class);
    rtRefused(fn () => rtRequest($f->keeper, $f->order, $f->line, '0'), ValidationException::class);
    rtRefused(fn () => rtRequest($f->keeper, $f->order, $f->line + 999, '1'), ValidationException::class);
});

it('T05 accept and T06 reject: P-102 from requested only', function () {
    $f = $this->f;
    $a = rtRequest($f->keeper, $f->order, $f->line, '2');
    rtRefused(fn () => rtAccept($f->keeper, $a), AuthorizationException::class);
    rtRefused(fn () => rtAccept($f->manager, $a, '3'), ValidationException::class);
    rtRefused(fn () => rtAccept($f->manager, $a, null, 99_999_999), ValidationException::class);
    rtRefused(fn () => app(T05AcceptReturn::class)->handle($f->manager, $a->refresh(), null, 'ok ok', null, $a->lock_version + 1, rtKey()), StaleRecord::class);

    expect(rtAccept($f->manager, $a)->status)->toBe(ReturnStatus::Accepted);
    rtRefused(fn () => rtAccept($f->manager, $a), InvalidTransition::class, 'not_from_accepted');
    rtRefused(fn () => app(T06RejectReturn::class)->handle($f->manager, $a->refresh(), 'خارج السياسة', $a->lock_version, rtKey()), InvalidTransition::class, 'not_from_accepted');

    $b = rtRequest($f->keeper, $f->order, $f->line, '1');
    rtRefused(fn () => app(T06RejectReturn::class)->handle($f->keeper, $b->refresh(), 'خارج السياسة', $b->lock_version, rtKey()), AuthorizationException::class);
    $before = DB::table('stock_movements')->count();
    expect(app(T06RejectReturn::class)->handle($f->manager, $b->refresh(), 'خارج السياسة', $b->lock_version, rtKey())->status)->toBe(ReturnStatus::Rejected)
        ->and(DB::table('stock_movements')->count())->toBe($before)
        ->and(DB::table('refunds')->count())->toBe(0);
    rtRefused(fn () => rtAccept($f->manager, $b), InvalidTransition::class, 'not_from_rejected');
});

it('T01/T07 receive and T02 inspect: P-53 on the order and the location, within what is expected', function () {
    $f = $this->f;
    $r = rtRequest($f->keeper, $f->order, $f->line, '2');
    rtRefused(fn () => rtReceive($f->keeper, $r, $f->location, '1'), InvalidTransition::class, 'not_from_requested');
    rtAccept($f->manager, $r);
    rtRefused(fn () => rtReceive($f->manager, $r, $f->location, '1'), AuthorizationException::class);
    rtRefused(fn () => rtReceive($f->keeper, $r, $f->location, '3'), ValidationException::class);
    rtRefused(fn () => rtReceive($f->keeper, $r, $f->s->lc, '1'), ValidationException::class);
    rtRefused(fn () => rtReceive($f->keeper, $r, $f->location, '1', ['1', '1', '0']), ValidationException::class);

    expect(rtReceive($f->keeper, $r, $f->location, '2')->status)->toBe(ReturnStatus::Received);
    rtRefused(fn () => rtReceive($f->keeper, $r, $f->location, '1'), InvalidTransition::class, 'nothing_expected');
    rtRefused(fn () => rtInspect($f->manager, $r, '2'), AuthorizationException::class);
    rtRefused(fn () => rtInspect($f->keeper, $r, '1'), ValidationException::class);
    expect(rtInspect($f->keeper, $r, '2')->status)->toBe(ReturnStatus::Inspected);
    $part = OrderReturnPart::query()->firstOrFail();
    rtRefused(fn () => rtInspect($f->keeper, $r, '2', part: $part), InvalidTransition::class, 'not_from_inspected');
});

it('T02 refuses a part already inspected while the return is still receiving', function () {
    $f = $this->f;
    $r = rtRequest($f->keeper, $f->order, $f->line, '3');
    rtAccept($f->manager, $r);
    rtReceive($f->keeper, $r, $f->location, '1', ['1', '0', '0']);
    $part = OrderReturnPart::query()->firstOrFail();
    rtReceive($f->keeper, $r, $f->location, '1');

    rtRefused(fn () => rtInspect($f->keeper, $r, '1', part: $part), InvalidTransition::class, 'already_inspected');
});

it('T03 withdraw: the requester before the decision, P-102 after; only what was not received', function () {
    $f = $this->f;
    $mine = rtRequest($f->keeper, $f->order, $f->line, '1');
    $other = $f->org->member('inventory', [$f->org->a]);
    rtRefused(fn () => rtWithdraw($other, $mine), AuthorizationException::class);
    expect(rtWithdraw($f->keeper, $mine)->status)->toBe(ReturnStatus::Cancelled);
    rtRefused(fn () => rtWithdraw($f->manager, $mine), InvalidTransition::class, 'not_from_cancelled');

    $r = rtRequest($f->keeper, $f->order, $f->line, '2');
    rtAccept($f->manager, $r);
    rtRefused(fn () => rtWithdraw($f->keeper, $r), AuthorizationException::class);
    rtReceive($f->keeper, $r, $f->location, '2', ['2', '0', '0']);
    rtRefused(fn () => rtWithdraw($f->manager, $r), InvalidTransition::class, 'not_from_inspected');

    $s = rtRequest($f->keeper, $f->order, $f->line, '1');
    rtAccept($f->manager, $s);
    $moves = StockMovement::query()->count();
    expect(rtWithdraw($f->manager, $s)->status)->toBe(ReturnStatus::Cancelled)
        ->and(StockMovement::query()->count())->toBe($moves);
});

it('T04 correct: P-53 with P-49, a counter movement, never above what is still available', function () {
    $f = $this->f;
    $r = rtRequest($f->keeper, $f->order, $f->line, '2');
    rtAccept($f->manager, $r);
    rtReceive($f->keeper, $r, $f->location, '2');
    $part = OrderReturnPart::query()->firstOrFail();
    rtRefused(fn () => rtCorrect($f->keeper, $r, $part->id, '1'), InvalidTransition::class, 'not_inspected');
    rtInspect($f->keeper, $r, '1', '1');

    rtRefused(fn () => rtCorrect($f->manager, $r, $part->id, '0'), AuthorizationException::class);
    rtRefused(fn () => rtCorrect($f->keeper, $r, $part->id, '1'), InvalidTransition::class, 'nothing_changed');
    rtRefused(fn () => rtCorrect($f->keeper, $r, $part->id, '3'), ValidationException::class);

    rtCorrect($f->keeper, $r, $part->id, '2');
    expect($part->refresh()->good_milli)->toBe(2000)->and($part->damaged_milli)->toBe(0)
        ->and(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id)['available'])->toBe('7');
    rtCorrect($f->keeper, $r, $part->id, '0');
    expect(stockBalance($f->location, 'ready_variant', $f->s->f->variant->id)['available'])->toBe('5')
        ->and(StateTransition::query()->where('machine', 'M-15')->where('transition', 'T04')->count())->toBe(2);
    stockJournalMatches();
});
