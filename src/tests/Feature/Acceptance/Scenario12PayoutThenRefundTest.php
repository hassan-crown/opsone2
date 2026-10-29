<?php

/**
 * Mandatory integration scenario 12 («صرف عمولة ثم رد», SRS-SAL-13, SRS-SAL-14, P06) end to end over HTTP: a paid
 * commission payout stays paid after a refund on its order; the refund shows as a negative difference on the member's
 * statement (no salary deduction, no erasure) and the available balance is re-checked before any other payout.
 */

use App\Enums\PayoutStatus;
use App\Models\Commissions\CommissionEntry;
use App\Models\Commissions\CommissionPayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptanceMoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = commissionFixture(500);
    agrSettings(0);
});

it('scenario 12: a payout of 50 then a refund of 400 keeps the payout and carries −20; the next payout is re-checked', function () {
    $f = $this->f;
    accMAgree($f, [finLine('مطبخ', '1000')]);
    $payment = accMPaid($f->finance, $f->order, '1000');
    expect(comAccrued($f->order))->toBe(5_000);

    // Finance pays the member's 50 with proof (P-96).
    $paid = accMPayout($f->finance, $f->sales)->assertOk();
    $payout = CommissionPayout::query()->findOrFail((int) $paid->json('payout_id'));
    expect($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->amount_minor)->toBe(5_000)
        ->and($paid->json('statement.summary.available_minor'))->toBe(0);

    // A refund of 400 is requested and executed on the order.
    $refund = (int) accMRefund($f->sales, $f->order, $payment, '400')->assertCreated()->json('refund_id');
    accMRefundConfirm($f->finance, $refund)->assertOk()->assertJsonPath('finance.summary.collected_minor', 60_000);

    // The payout is kept as paid; the accrual drops to 30 by a −20 difference, shown as a negative balance.
    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($payout->amount_minor)->toBe(5_000)
        ->and(CommissionEntry::query()->orderBy('id')->pluck('amount_minor')->all())->toBe([5_000, -2_000])
        ->and(comAccrued($f->order))->toBe(3_000);
    $statement = accMStatement($f->owner, $f->sales);
    expect($statement['summary']['available_minor'])->toBe(-2_000)
        ->and($statement['entries'][0]['amount_minor'])->toBe(-2_000)
        ->and($statement['payouts'][0]['status'])->toBe('paid')
        // No salary deduction or automatic correction of the paid payout.
        ->and(CommissionPayout::query()->count())->toBe(1)
        ->and($payout->corrected_minor)->toBeIn([null, 0]);

    // Nothing positive is available: a new payout is refused.
    accMPayout($f->finance, $f->sales)->assertStatus(409);
    expect(CommissionPayout::query()->where('status', PayoutStatus::Paid->value)->count())->toBe(1);
});

it('scenario 12: after the negative difference, a later collection on another order is paid only net of it (positive balance is the limit)', function () {
    $f = $this->f;
    accMAgree($f, [finLine('مطبخ', '1000')]);
    $payment = accMPaid($f->finance, $f->order, '1000');
    accMPayout($f->finance, $f->sales)->assertOk();
    $refund = (int) accMRefund($f->sales, $f->order, $payment, '400')->assertCreated()->json('refund_id');
    accMRefundConfirm($f->finance, $refund)->assertOk();

    // Another order of the same member collects 1,000 → +50; the −20 carried forward leaves 30 to pay.
    $f->order = openCustomOrder($f->sales, $f->order->customer);
    accMAgree($f, [finLine('مطبخ', '1000')]);
    accMPaid($f->finance, $f->order, '1000');
    expect(accMStatement($f->owner, $f->sales)['summary']['available_minor'])->toBe(3_000);
    $second = accMPayout($f->finance, $f->sales)->assertOk();
    expect(CommissionPayout::query()->findOrFail((int) $second->json('payout_id'))->amount_minor)->toBe(3_000);
});
