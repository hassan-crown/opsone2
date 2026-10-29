<?php

/**
 * Mandatory integration scenario 10 («عربون600 ثم اتفاق منتجات1000 وإجمالي1200 وعمولة5%», SRS-INT-06, SRS-FIN-03, P06)
 * end to end over HTTP: a deposit of 600 before any agreement stays unallocated and earns nothing; approving the
 * agreement allocates it and accrues 25 without another payment; unallocated money never earns commission.
 */

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
    // Facility policy 5%; VAT 0 so the documents' gross numbers are the commission basis as written.
    $this->f = commissionFixture(500);
    agrSettings(0);
    $this->year = now()->format('Y');
});

it('scenario 10: deposit 600, then agreement products 1,000 / total 1,200 at 5% → 25 accrued with no other payment', function () {
    $f = $this->f;

    $deposit = accMPay($f->finance, $f->order, '600')->assertCreated();
    expect($deposit->json('receipt'))->toBe("RC-{$this->year}-000001")
        ->and($deposit->json('finance.summary.collected_minor'))->toBe(60_000)
        ->and($deposit->json('finance.summary.credit_minor'))->toBe(60_000)
        // No agreement yet: no sale, no commission, no dues.
        ->and(DB::table('order_sales')->count())->toBe(0)
        ->and(CommissionEntry::query()->count())->toBe(0)
        ->and(DB::table('order_dues')->count())->toBe(0);
    // The customer is sent the receipt.
    $receipt = Receipt::query()->sole();
    expect(DB::table('outbox_messages')->where('idempotency_key', "receipt:{$receipt->id}:sms")->exists())->toBeTrue();

    accMAgree($f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);

    // The deposit is allocated to the first due (50% = 600) without a new receipt.
    expect(finDues($f->order))->toBe(['first' => ['amount' => 60_000, 'allocated' => 60_000], 'final' => ['amount' => 60_000, 'allocated' => 0]])
        ->and(Receipt::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1);

    // 5% × 1,000 × (600 / 1,200) = 25.00, in one difference, owned by the order's sales member at the pinned 5%.
    $entry = CommissionEntry::query()->sole();
    expect($entry->user_id)->toBe($f->sales->id)
        ->and($entry->amount_minor)->toBe(2_500)
        ->and($entry->eligible_minor)->toBe(100_000)
        ->and($entry->agreed_minor)->toBe(120_000)
        ->and($entry->collected_minor)->toBe(60_000)
        ->and(DB::table('order_sales')->where('order_id', $f->order->id)->value('rate_bp'))->toBe(500);

    $statement = accMStatement($f->owner, $f->sales);
    expect($statement['sales'][0]['order']['id'])->toBe($f->order->id)
        ->and($statement['sales'][0]['accrued_minor'])->toBe(2_500)
        ->and($statement['sales'][0]['collected_minor'])->toBe(60_000)
        ->and($statement['payable']['amount_minor'])->toBe(2_500);

    // The approval and the collection are audited.
    expect(accMAudit('finance.payment_recorded'))->toHaveCount(1)
        ->and(accMAudit('agreement.activated'))->toHaveCount(1);
});

it('scenario 10: unallocated money earns no commission — 1,500 paid on 1,200 accrues on 1,200 only and keeps 300 as credit', function () {
    $f = $this->f;
    accMAgree($f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);

    $paid = accMPay($f->finance, $f->order, '1500')->assertCreated();
    expect($paid->json('finance.summary.credit_minor'))->toBe(30_000)
        ->and($paid->json('finance.summary.collected_minor'))->toBe(150_000);
    // 5% × 1,000 × min(1,200 / 1,200) = 50, never 5% of the extra 300.
    expect(comAccrued($f->order))->toBe(5_000)
        ->and(accMStatement($f->owner, $f->sales)['sales'][0]['accrued_minor'])->toBe(5_000);
});
