<?php

/**
 * Commission parts of the mandatory integration scenarios («اختبارات ترابط ملزمة») 10, 11 and 12 with the documents'
 * numbers, and the accrual rules of SAL-10..13, SAL-19 and INT-06.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M33Payout\T01ApprovePayout;
use App\Commissions\CommissionBalances;
use App\Commissions\LedgerSettlementHolds;
use App\Commissions\SettlementHolds;
use App\Enums\PayoutStatus;
use App\Finance\MoneyLedger;
use App\Models\Commissions\CommissionEntry;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/CommissionTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = commissionFixture(500);
});

it('scenario 10: deposit 600, then agreement products 1,000 / total 1,200 at 5% → 25 accrued without another payment; unallocated money earns nothing', function () {
    agrSettings(0);
    finPay($this->f->finance, $this->f->order, '600');
    expect(comSale($this->f->order))->toBeNull()
        ->and(CommissionEntry::query()->count())->toBe(0);

    agrAgreed($this->f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);

    $sale = comSale($this->f->order);
    expect($sale->user_id)->toBe($this->f->sales->id)
        ->and($sale->rate_bp)->toBe(500)
        ->and(comAccrued($this->f->order))->toBe(2_500)
        // One difference, whichever listener ran first.
        ->and(CommissionEntry::query()->count())->toBe(1);
});

it('scenario 10 with VAT 15% inclusive: the basis is the eligible products before VAT (1,150 inclusive = 1,000 net) → 25', function () {
    finPay($this->f->finance, $this->f->order, '600');
    agrAgreed($this->f, [finLine('مطبخ', '1150'), finLine('رسوم نقل', '50', false)]);

    $entry = CommissionEntry::query()->sole();
    expect($entry->eligible_minor)->toBe(100_000)
        ->and($entry->agreed_minor)->toBe(120_000)
        ->and($entry->collected_minor)->toBe(60_000)
        ->and($entry->amount_minor)->toBe(2_500);
});

it('scenario 10: an unallocated overpayment earns no commission', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    finPay($this->f->finance, $this->f->order, '1500');

    // 1,200 allocated (the whole agreement) → 5% of 1,000; the 300 credit adds nothing.
    expect(MoneyLedgerCredit($this->f->order))->toBe(30_000)
        ->and(comAccrued($this->f->order))->toBe(5_000);
});

it('scenario 11: agreement lowered to products 500 / total 600 with 300 to refund → payout held until settled; after the refund 300 net → 12.5', function () {
    agrSettings(0);
    $first = finPay($this->f->finance, $this->f->order, '300');
    $second = finPay($this->f->finance, $this->f->order, '300');
    agrAgreed($this->f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    expect(comAccrued($this->f->order))->toBe(2_500);

    agrApplyChange($this->f->owner, agrChange($this->f->owner, $this->f->order, [finLine('مطبخ', '500'), finLine('تركيب ونقل', '100', false)]));
    // 6c's pending refund of 300 holds the order (bound by 6c; simulated here).
    app()->instance(SettlementHolds::class, new class implements SettlementHolds
    {
        public function holdOf(Order $order): ?string
        {
            return 'refund';
        }
    });
    $payable = app(CommissionBalances::class)->payable($this->f->sales->id, now());
    expect($payable['lines'])->toBe([])
        ->and($payable['held'])->toBe([['order_id' => $this->f->order->id, 'hold' => 'refund', 'amount_minor' => 2_500]]);

    comReverse($second, $this->f->order);
    app()->forgetInstance(SettlementHolds::class);
    app()->bind(SettlementHolds::class, LedgerSettlementHolds::class);

    expect(MoneyLedger::collected($this->f->order->id)->minor)->toBe(30_000)
        ->and(comAccrued($this->f->order))->toBe(1_250)
        // The earlier accrual is corrected by a difference, never rewritten.
        ->and(CommissionEntry::query()->orderBy('id')->pluck('amount_minor')->all())->toBe([2_500, -1_250])
        ->and(app(CommissionBalances::class)->payable($this->f->sales->id, now())['amount_minor'])->toBe(1_250);
    expect($first->refresh()->status->value)->toBe('confirmed');
});

it('scenario 12: a payout then a refund keeps the payout and carries a negative difference; no salary deduction', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    $payout = comPay($this->f->finance, $this->f->sales);
    expect($payout->status)->toBe(PayoutStatus::Paid)->and($payout->amount_minor)->toBe(5_000);

    comReverse($payment, $this->f->order);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and(comAccrued($this->f->order))->toBe(0)
        ->and(app(CommissionBalances::class)->summary($this->f->sales->id)['available_minor'])->toBe(-5_000);
    // The available balance is checked again before any other payout.
    $draft = comDraft($this->f->finance, $this->f->sales);
    comRefused(fn () => app(T01ApprovePayout::class)->handle(
        $this->f->finance, $draft, $draft->lock_version, agrKey(),
    ), InvalidTransition::class, 'nothing_available');
});

it('pins the rate at the agreement: later policy, role or member rate changes never reach the sale (SAL-19)', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    finPay($this->f->finance, $this->f->order, '500');
    expect(comAccrued($this->f->order))->toBe(2_500);

    comPolicy($this->f->owner, 1000, members: [['user_id' => $this->f->sales->id, 'rate_bp' => 2000]]);
    finPay($this->f->finance, $this->f->order, '500');

    expect(comSale($this->f->order)->rate_bp)->toBe(500)
        ->and(comAccrued($this->f->order))->toBe(5_000);

    // A new sale takes the member's rate of the day.
    $other = openCustomOrder($this->f->sales, $this->f->order->customer);
    $this->f->order = $other;
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    expect(comSale($other)->rate_bp)->toBe(2000)->and(comSale($other)->rate_source)->toBe('member');
});

it('disabled commissions pin 0% and accrue nothing; turning them on later does not reach that sale', function () {
    comPolicy($this->f->owner, 500, enabled: false);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    finPay($this->f->finance, $this->f->order, '1000');
    expect(comSale($this->f->order)->rate_source)->toBe('disabled')->and(comAccrued($this->f->order))->toBe(0);

    comPolicy($this->f->owner, 500);
    comRecompute($this->f->order);
    expect(comAccrued($this->f->order))->toBe(0);
});

it('partial collections sum exactly to the commission of the whole, rounded once at each accrual (Q-34 a)', function () {
    agrSettings(0);
    comPolicy($this->f->owner, 333);
    agrAgreed($this->f, [finLine('مطبخ', '1000.01'), finLine('نقل', '0.02', false)]);
    foreach (['333.33', '333.33', '0.01', '333.36'] as $part) {
        finPay($this->f->finance, $this->f->order, $part);
    }

    // 100,001 × 3.33% = 3,330.0333 → 3,330 halalas, whatever the parts.
    expect(comAccrued($this->f->order))->toBe(3_330)
        ->and((int) CommissionEntry::query()->sum('amount_minor'))->toBe(3_330)
        ->and(CommissionEntry::query()->orderByDesc('id')->value('accrued_minor'))->toBe(3_330);
});

it('a proof under review is not money and earns nothing; its confirmation does (SAL-12)', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    $proof = finProof($this->f->order, '400');
    expect(comAccrued($this->f->order))->toBe(0);

    finConfirm($this->f->finance, $proof);
    expect(comAccrued($this->f->order))->toBe(2_000);
});

it('the refund hook reverses the accrual of the reversed money, and a repeated event adds nothing (SAL-13)', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    $a = finPay($this->f->finance, $this->f->order, '600');
    finPay($this->f->finance, $this->f->order, '400');
    expect(comAccrued($this->f->order))->toBe(5_000);

    comReverse($a, $this->f->order);
    expect(comAccrued($this->f->order))->toBe(2_000);

    $count = CommissionEntry::query()->count();
    expect(comRecompute($this->f->order))->toBeNull()
        ->and(CommissionEntry::query()->count())->toBe($count);
});

it('holds only the order in settlement: a lowered agreement with money above it is held, the member\'s other orders stay payable', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    finPay($this->f->finance, $this->f->order, '900');
    $first = $this->f->order;

    $this->f->order = openCustomOrder($this->f->sales, $first->customer);
    agrAgreed($this->f, [finLine('مطبخ', '400')]);
    finPay($this->f->finance, $this->f->order, '400');

    agrApplyChange($this->f->owner, agrChange($this->f->owner, $first, [finLine('مطبخ', '500'), finLine('تركيب ونقل', '100', false)]));
    $payable = app(CommissionBalances::class)->payable($this->f->sales->id, now());

    expect($payable['held'])->toBe([['order_id' => $first->id, 'hold' => 'settlement', 'amount_minor' => 2_500]])
        ->and($payable['amount_minor'])->toBe(2_000);

    $payout = comPay($this->f->finance, $this->f->sales);
    expect($payout->amount_minor)->toBe(2_000)
        ->and($payout->lines()->pluck('order_id')->all())->toBe([$this->f->order->id]);
});

function MoneyLedgerCredit(Order $order): int
{
    return MoneyLedger::credit($order->id)->minor;
}
