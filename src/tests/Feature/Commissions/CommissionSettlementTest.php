<?php

/**
 * The seams between 6c settlement and 6d commissions: an executed refund and a payment correction re-derive the
 * sale's commission under their own source, and a pending refund or an open dispute holds the order from payouts.
 */

use App\Commissions\CommissionBalances;
use App\Commissions\SettlementHolds;
use App\Models\Commissions\CommissionEntry;
use App\Models\Finance\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/CommissionTestHelpers.php';
require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = commissionFixture(500);
    $this->year = now()->format('Y');
});

it('an executed refund reduces the commission by a difference named after the refund', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    expect(comAccrued($this->f->order))->toBe(5_000);

    $refund = setRefund($this->f->finance, $this->f->order, $payment, '400');
    // Requested only: no money left yet, the commission is unchanged.
    expect(comAccrued($this->f->order))->toBe(5_000);
    $refund = setPaid($this->f->finance, $refund);

    $last = CommissionEntry::query()->latest('id')->firstOrFail();
    expect(comAccrued($this->f->order))->toBe(3_000)
        ->and($last->amount_minor)->toBe(-2_000)
        ->and($last->source)->toBe('refund:'.$refund->id)
        ->and($last->reason)->toBe($refund->number);
    setLedgerBalanced();
});

it('a payment correction recomputes the commission: amend to the new amount, void to nothing', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    $payment = finPay($this->f->finance, $this->f->order, '1000', ['reference' => 'FT-2001']);
    expect(comAccrued($this->f->order))->toBe(5_000);

    $amend = setCorrect($this->f->finance, $this->f->order, $payment, [
        'kind' => 'amend', 'amount' => '800', 'method' => 'transfer', 'paid_on' => $payment->paid_on->toDateString(), 'reference' => 'FT-2001',
    ]);
    expect(comAccrued($this->f->order))->toBe(4_000);

    $replacement = $amend->replacement_payment_id;
    $void = setCorrect($this->f->finance, $this->f->order, Payment::query()->findOrFail($replacement), ['kind' => 'void']);

    $last = CommissionEntry::query()->latest('id')->firstOrFail();
    expect(comAccrued($this->f->order))->toBe(0)
        ->and($last->amount_minor)->toBe(-4_000)
        ->and($last->source)->toBe('correction:'.$void->number);
    setLedgerBalanced();
});

it('a pending refund or an open dispute holds the order from payouts; settled, it is payable again', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    $payment = finPay($this->f->finance, $this->f->order, '1000');
    $holds = app(SettlementHolds::class);
    expect($holds->holdOf($this->f->order))->toBeNull();

    $refund = setRefund($this->f->finance, $this->f->order, $payment, '100');
    expect($holds->holdOf($this->f->order))->toBe('refund')
        ->and(app(CommissionBalances::class)->payable($this->f->sales->id, now())['lines'])->toBe([]);

    setPaid($this->f->finance, $refund);
    expect($holds->holdOf($this->f->order->refresh()))->toBeNull()
        ->and(app(CommissionBalances::class)->payable($this->f->sales->id, now())['amount_minor'])->toBe(4_500);

    setDispute($this->f->finance, $this->f->order, ['amount' => '100', 'payment_id' => $payment->id]);
    expect($holds->holdOf($this->f->order))->toBe('dispute');
});
