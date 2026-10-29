<?php

/**
 * Mandatory integration scenario 11 («تخفيض الاتفاق السابق إلى منتجات500 وإجمالي600 مع رد مستحق300», SRS-INT-05,
 * SRS-INT-06, P04/P05/P06) end to end over HTTP, continuing scenario 10 (deposit 600, agreement products 1,000 /
 * total 1,200, 5%): the agreement is lowered once by its change, the order's commission payout is held while the
 * refund of 300 is open, and after the refund the net collected is 300 and the accrual 12.5 — corrected by a
 * difference, the money lowered once.
 */

use App\Models\Agreements\Agreement;
use App\Models\Commissions\CommissionEntry;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptanceMoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = commissionFixture(500);
    agrSettings(0);
    $this->year = now()->format('Y');
});

it('scenario 11: lowering 1,200 to 600 with 300 to refund holds the payout until settled; after the refund 300 net → 12.5', function () {
    $f = $this->f;
    $deposit = accMPaid($f->finance, $f->order, '600');
    accMAgree($f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    expect(comAccrued($f->order))->toBe(2_500);

    // Sales prepares the lowered agreement; the owner records the customer's approval (P-36).
    $change = accMChange($f->sales, $f->order, [finLine('مطبخ', '500'), finLine('تركيب ونقل', '100', false)]);
    expect($change->difference_minor)->toBe(-60_000);
    accMApplyChange($f->owner, $change)->assertOk()
        ->assertJsonPath('agreement.agreement.version', 2)
        ->assertJsonPath('agreement.agreement.total_minor', 60_000);
    $current = Agreement::query()->where('order_id', $f->order->id)->where('status', 'active')->sole();
    expect($current->products_minor)->toBe(50_000)
        ->and($current->previous_total_minor)->toBe(120_000)
        ->and(Agreement::query()->where('order_id', $f->order->id)->where('version', 1)->value('total_minor'))->toBe(120_000)
        // 600 collected on 600 agreed: the commission basis did not change (5% × 500 × 100% = 25).
        ->and(comAccrued($f->order))->toBe(2_500);

    // The refund of 300 due to the customer is requested: the order's commission is held from payout until settled.
    $refund = (int) accMRefund($f->sales, $f->order, $deposit, '300')->assertCreated()->json('refund_id');
    $payable = accMStatement($f->owner, $f->sales)['payable'];
    expect($payable['lines'])->toBe([])
        ->and($payable['amount_minor'])->toBe(0)
        ->and($payable['held'])->toBe([['order_id' => $f->order->id, 'hold' => 'refund', 'amount_minor' => 2_500]]);
    accMPayout($f->finance, $f->sales)->assertStatus(409);

    // Finance pays the refund with proof.
    $paid = accMRefundConfirm($f->finance, $refund)->assertOk();
    expect($paid->json('number'))->toBe("RF-{$this->year}-000001")
        ->and($paid->json('finance.summary.collected_minor'))->toBe(30_000)
        ->and($paid->json('finance.summary.agreed_minor'))->toBe(60_000);

    // 5% × 500 × (300 / 600) = 12.50; the earlier 25 is corrected by a −12.50 difference, never rewritten.
    expect(comAccrued($f->order))->toBe(1_250)
        ->and(CommissionEntry::query()->orderBy('id')->pluck('amount_minor')->all())->toBe([2_500, -1_250]);
    $statement = accMStatement($f->owner, $f->sales);
    expect($statement['sales'][0]['accrued_minor'])->toBe(1_250)
        ->and($statement['payable']['held'])->toBe([])
        ->and($statement['payable']['amount_minor'])->toBe(1_250);

    // The value was lowered once (by the change) and the money once (by the refund): the deposit and its receipt stay.
    expect(Payment::query()->findOrFail($deposit)->amount_minor)->toBe(60_000)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(Agreement::query()->where('order_id', $f->order->id)->count())->toBe(2)
        ->and((int) DB::table('money_entries')->join('money_journals', 'money_journals.id', '=', 'money_entries.journal_id')
            ->where('money_journals.kind', 'refund')->sum('money_entries.amount_minor'))->toBe(-30_000);
    expect(accMAudit('agreement_change.applied'))->toHaveCount(1)
        ->and(accMAudit('refund.'))->toBe(['refund.requested', 'refund.executed']);
    setLedgerBalanced();
});
