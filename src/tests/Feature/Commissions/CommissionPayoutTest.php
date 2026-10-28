<?php

/**
 * M-33 «صرف العمولة» (SAL-14): every transition allowed from its states and refused from the others without a side
 * effect, idempotent double submits, the reservation and the correction of a recorded payout.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M33Payout\T01ApprovePayout;
use App\Actions\StateMachines\M33Payout\T02RecordPayout;
use App\Actions\StateMachines\M33Payout\T03CancelPayout;
use App\Actions\StateMachines\M33Payout\T04CorrectPayout;
use App\Commissions\CommissionBalances;
use App\Enums\PayoutStatus;
use App\Models\Commissions\CommissionPayout;
use App\Models\StateTransition;
use App\Support\StaleRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/CommissionTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = commissionFixture(500);
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    finPay($this->f->finance, $this->f->order, '1000');
});

/**
 * A payout of `sales` brought to the given state.
 */
function comPayoutIn(object $f, string $state): CommissionPayout
{
    $draft = comDraft($f->finance, $f->sales);

    return match ($state) {
        'draft' => $draft,
        'reserved' => app(T01ApprovePayout::class)->handle($f->finance, $draft, $draft->lock_version, agrKey()),
        'paid' => app(T01ApprovePayout::class)->handle($f->finance, $draft, $draft->lock_version, agrKey(), comExecution(), agrPdf('p.pdf', 'x')),
        'cancelled' => app(T03CancelPayout::class)->handle($f->finance, $draft, 'خطأ في الفترة', $draft->lock_version),
        'corrected' => comTransition($f, 'T04', app(T01ApprovePayout::class)->handle($f->finance, $draft, $draft->lock_version, agrKey(), comExecution(), agrPdf('p.pdf', 'x'))),
    };
}

function comTransition(object $f, string $code, CommissionPayout $payout): CommissionPayout
{
    return match ($code) {
        'T01' => app(T01ApprovePayout::class)->handle($f->finance, $payout, $payout->lock_version, agrKey()),
        'T02' => app(T02RecordPayout::class)->handle($f->finance, $payout, comExecution(), agrPdf('proof.pdf', 'y'), $payout->lock_version, agrKey()),
        'T03' => app(T03CancelPayout::class)->handle($f->finance, $payout, 'عدول', $payout->lock_version),
        'T04' => app(T04CorrectPayout::class)->handle($f->owner, $payout, ['amount' => '40.00', 'reason' => 'خرج 40 فقط'], agrPdf('fix.pdf', 'z'), $payout->lock_version, agrKey()),
    };
}

it('M-33 {1} from {0}: allowed only from its states; a refusal changes nothing', function (string $state, string $code) {
    $payout = comPayoutIn($this->f, $state);
    $allowed = PayoutStatus::transitions()[$code];

    if (in_array(PayoutStatus::from($state), $allowed['from'], true)) {
        $after = comTransition($this->f, $code, $payout->refresh());
        expect($after->status)->toBe($allowed['to'])
            ->and(StateTransition::query()->where('machine', 'M-33')->where('transition', $code)->where('subject_id', $payout->id)->count())->toBe(1);
    } else {
        comRefused(fn () => comTransition($this->f, $code, $payout->refresh()), InvalidTransition::class, 'not_from_'.$state);
    }
})->with(['draft', 'reserved', 'paid', 'cancelled', 'corrected'])->with(['T01', 'T02', 'T03', 'T04']);

it('T01 holds the payable lines at their amount and a second payout cannot take them', function () {
    $reserved = comPayoutIn($this->f, 'reserved');
    expect($reserved->amount_minor)->toBe(5_000)
        ->and(app(CommissionBalances::class)->payable($this->f->sales->id, now())['amount_minor'])->toBe(0);

    comRefused(fn () => comDraft($this->f->finance, $this->f->sales), InvalidTransition::class, 'payout_open');

    app(T03CancelPayout::class)->handle($this->f->finance, $reserved, 'عدول', $reserved->lock_version);
    // A cancelled payout releases its lines; nothing was paid.
    expect(app(CommissionBalances::class)->payable($this->f->sales->id, now())['amount_minor'])->toBe(5_000);
});

it('a double submit of approve-and-pay replays the first result: one payout, one T02, one proof', function () {
    $draft = comDraft($this->f->finance, $this->f->sales);
    $key = agrKey();
    $execution = comExecution();
    $first = app(T01ApprovePayout::class)->handle($this->f->finance, $draft, $draft->lock_version, $key, $execution, agrPdf('p.pdf', 'same'));
    $again = app(T01ApprovePayout::class)->handle($this->f->finance, $draft, $draft->lock_version, $key, $execution, agrPdf('p.pdf', 'same'));

    expect($again->id)->toBe($first->id)
        ->and($again->status)->toBe(PayoutStatus::Paid)
        ->and(StateTransition::query()->where('machine', 'M-33')->where('transition', 'T02')->count())->toBe(1)
        ->and($first->attachments()->count())->toBe(1)
        ->and($first->lines()->count())->toBe(1);

    // Another attempt with a new key is refused from `paid`, without a side effect.
    comRefused(fn () => app(T02RecordPayout::class)->handle($this->f->finance, $first->refresh(), comExecution(), agrPdf('p.pdf', 'other'), $first->lock_version, agrKey()),
        InvalidTransition::class, 'not_from_paid');
});

it('a double submit of the draft creates one payout', function () {
    $key = agrKey();
    $a = comDraft($this->f->finance, $this->f->sales, $key);
    $b = comDraft($this->f->finance, $this->f->sales, $key);
    expect($b->id)->toBe($a->id)->and(CommissionPayout::query()->count())->toBe(1);
});

it('refuses a stale screen and an empty balance without a side effect', function () {
    $draft = comDraft($this->f->finance, $this->f->sales);
    comRefused(fn () => app(T01ApprovePayout::class)->handle($this->f->finance, $draft, $draft->lock_version + 1, agrKey()), StaleRecord::class);

    $empty = comDraft($this->f->finance, $this->f->sales2);
    comRefused(fn () => app(T01ApprovePayout::class)->handle($this->f->finance, $empty, $empty->lock_version, agrKey()), InvalidTransition::class, 'nothing_available');
});

it('T04 keeps the original and turns the difference into an adjustment: 50 recorded, 40 really left → 10 available again', function () {
    $paid = comPayoutIn($this->f, 'paid');
    $corrected = comTransition($this->f, 'T04', $paid);

    expect($corrected->amount_minor)->toBe(5_000)
        ->and($corrected->corrected_minor)->toBe(4_000)
        ->and(app(CommissionBalances::class)->summary($this->f->sales->id))->toMatchArray(['paid_minor' => 4_000, 'available_minor' => 1_000]);

    $next = comPay($this->f->finance, $this->f->sales);
    expect($next->amount_minor)->toBe(1_000)
        ->and($next->lines()->whereNull('order_sale_id')->sum('amount_minor'))->toBe(1_000)
        ->and(app(CommissionBalances::class)->summary($this->f->sales->id)['available_minor'])->toBe(0);
});

it('nobody records a payout to themselves', function () {
    comRefused(fn () => comDraft($this->f->finance, $this->f->finance), InvalidTransition::class, 'own_payout');
});
