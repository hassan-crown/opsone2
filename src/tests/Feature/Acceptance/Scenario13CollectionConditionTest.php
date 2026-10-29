<?php

/**
 * Mandatory integration scenario 13 («شرط50% ثم100%», SRS-INT-07, SRS-FIN-02, P01/P05) end to end over HTTP: the
 * stage checks «first_payment» (50% in the agreement stage) and «final_payment» (100%, installation stage) compare the
 * cumulative net collected of the same agreement without consuming it; the two checks never create two receipts for
 * one payment, and a later refund never un-completes a met stage — it only shows what is no longer covered.
 *
 * Reaching the agreement stage (measurement and design stages done) is fixture setup; every money step is HTTP.
 */

use App\Enums\ActionStatus;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Workflow\StageEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptanceMoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    agrSettings(0);
    $this->year = now()->format('Y');
});

it('scenario 13: 50% then 100% of 1,200 compare the cumulative net collected — one receipt per payment, nothing consumed', function () {
    $f = $this->f;
    accMAgree($f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    finToAgreementStage($f);

    // 599.99 of the 600 threshold: not met.
    accMPaid($f->finance, $f->order, '599.99');
    expect(actionOf($f->order, 'first_payment')->status)->not->toBe(ActionStatus::Verified);

    // +0.01 → cumulative 600.00 = 50%: met by that payment, without a new receipt for the check.
    $second = accMPaid($f->finance, $f->order, '0.01');
    expect(actionOf($f->order, 'first_payment')->status)->toBe(ActionStatus::Verified)
        ->and(actionOf($f->order, 'first_payment')->source_reference)->toBe(['type' => 'order_collection', 'id' => $second])
        ->and(actionOf($f->order, 'final_payment')->source_reference)->toBeNull();

    // +600 → cumulative 1,200 = 100%: the same money still counts for 50%; the 100% check is met by the third payment.
    $third = accMPaid($f->finance, $f->order, '600');
    expect(actionOf($f->order, 'final_payment')->source_reference)->toBe(['type' => 'order_collection', 'id' => $third])
        ->and(actionOf($f->order, 'first_payment')->status)->toBe(ActionStatus::Verified)
        ->and(Receipt::query()->count())->toBe(3)
        ->and(Payment::query()->count())->toBe(3)
        ->and(Receipt::query()->orderBy('id')->pluck('number')->all())->toBe(["RC-{$this->year}-000001", "RC-{$this->year}-000002", "RC-{$this->year}-000003"])
        ->and(accMFinance($f->finance, $f->order)['finance']['summary']['collected_minor'])->toBe(120_000);
});

it('scenario 13: a refund below 50% never un-completes the met stage — the shortfall is shown to the authorized person', function () {
    $f = $this->f;
    accMAgree($f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    finToAgreementStage($f);
    $payment = accMPaid($f->finance, $f->order, '1200');
    expect(actionOf($f->order, 'first_payment')->status)->toBe(ActionStatus::Verified);

    // Refund 800: net 400 is below 50% (600).
    $refund = (int) accMRefund($f->finance, $f->order, $payment, '800')->assertCreated()->json('refund_id');
    accMRefundConfirm($f->finance, $refund)->assertOk()->assertJsonPath('finance.summary.collected_minor', 40_000);
    expect(actionOf($f->order, 'first_payment')->status)->toBe(ActionStatus::Verified)
        ->and(accMFinance($f->finance, $f->order)['finance']['shortfalls'])->toContain('first')
        ->and(Receipt::query()->count())->toBe(1);
});

it('scenario 13: a refund stops only the affected later check — 100% is no longer met when its stage opens', function () {
    $f = $this->f;
    accMAgree($f, [finLine('مطبخ', '1000'), finLine('تركيب ونقل', '200', false)]);
    finToAgreementStage($f);
    $payment = accMPaid($f->finance, $f->order, '1200');
    expect(actionOf($f->order, 'final_payment')->source_reference)->toBe(['type' => 'order_collection', 'id' => $payment]);

    // Refund 300: net 900 still covers 50% (600) but no longer 100% (1,200).
    $refund = (int) accMRefund($f->finance, $f->order, $payment, '300')->assertCreated()->json('refund_id');
    accMRefundConfirm($f->finance, $refund)->assertOk()->assertJsonPath('finance.summary.collected_minor', 90_000);
    expect(actionOf($f->order, 'first_payment')->status)->toBe(ActionStatus::Verified);

    // Work moves on (fixture: agreement and manufacturing stages done) until the installation stage opens.
    finishActiveStage($f->owner, $f->order);
    finishActiveStage($f->owner, $f->order);
    expect(app(StageEngine::class)->activeStage($f->order->refresh())->id)->toBe(actionOf($f->order, 'final_payment')->order_stage_id);

    // The 100% check must wait for the missing 300 — not be verified from the payment that was partly refunded.
    expect(actionOf($f->order, 'final_payment')->status)->not->toBe(ActionStatus::Verified);
});
