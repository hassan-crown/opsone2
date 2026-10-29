<?php

/**
 * Mandatory integration scenario 9 («ردان300 و800 من قابل للرد1000», SRS-FIN-09, P05) end to end over HTTP: with 1,000
 * refundable, a pending 300 reserves its share so 800 is refused; a sent refund whose result is not known keeps the
 * reserve; a final failure releases it; the request alone never lowers the collected money.
 */

use App\Enums\RefundStatus;
use App\Models\Finance\MoneyJournal;
use App\Models\Finance\Receipt;
use App\Models\Finance\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptanceMoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    $this->year = now()->format('Y');
});

it('scenario 9: 300 then 800 from 1,000 refundable — reserve, refuse, unknown keeps, failure releases, 800 executes once', function () {
    $f = $this->f;
    accMAgree($f, [finLine('مطبخ', '1000')]);
    $payment = accMPaid($f->finance, $f->order, '1000');
    expect(accMFinance($f->finance, $f->order)['settlement']['payments'][0]['refundable_minor'])->toBe(100_000);

    // Sales requests 300 (P-42): reserved, but the collected money is untouched (FIN-09).
    $first = (int) accMRefund($f->sales, $f->order, $payment, '300')->assertCreated()->json('refund_id');
    $money = accMFinance($f->finance, $f->order);
    expect($money['finance']['summary']['collected_minor'])->toBe(100_000)
        ->and($money['settlement']['payments'][0]['refundable_minor'])->toBe(70_000)
        ->and(Refund::query()->findOrFail($first)->status)->toBe(RefundStatus::Requested);

    // 800 does not fit next to the pending 300.
    accMRefund($f->finance, $f->order, $payment, '800')->assertStatus(409)->assertJson(['condition' => 'not_refundable']);

    // Finance sends the 300 (P-43): the result is not known yet — the reserve stays and 800 is still refused.
    test()->actingAs($f->finance)->postJson("/finance/refunds/{$first}/start", ['reference' => 'TRX-300', 'idempotency_key' => accMKey()])->assertOk();
    expect(Refund::query()->findOrFail($first)->status)->toBe(RefundStatus::InProgress);
    accMRefund($f->finance, $f->order, $payment, '800')->assertStatus(409)->assertJson(['condition' => 'not_refundable']);

    // A final failure (the bank returned it) releases the reserve; nothing left the ledger.
    test()->actingAs($f->finance)->postJson("/finance/refunds/{$first}/fail", ['reason' => 'رفض البنك التحويل.', 'idempotency_key' => accMKey()])
        ->assertOk()->assertJsonPath('finance.summary.collected_minor', 100_000);
    expect(Refund::query()->findOrFail($first)->status)->toBe(RefundStatus::Failed);

    $second = (int) accMRefund($f->finance, $f->order, $payment, '800')->assertCreated()->json('refund_id');
    expect(accMFinance($f->finance, $f->order)['settlement']['payments'][0]['refundable_minor'])->toBe(20_000);

    // Retrying the failed 300 re-checks the limit: it no longer fits next to 800.
    test()->actingAs($f->finance)->postJson("/finance/refunds/{$first}/retry", ['idempotency_key' => accMKey()])
        ->assertStatus(409)->assertJson(['condition' => 'not_refundable']);

    $paid = accMRefundConfirm($f->finance, $second, 'TRX-800')->assertOk();
    expect($paid->json('number'))->toBe("RF-{$this->year}-000001")
        ->and($paid->json('finance.summary.collected_minor'))->toBe(20_000)
        ->and($paid->json('settlement.payments.0.refundable_minor'))->toBe(20_000)
        ->and(Refund::query()->findOrFail($second)->status)->toBe(RefundStatus::Executed);

    // One refund journal of exactly −800; the payment and its receipt stay as records.
    $journal = MoneyJournal::query()->where('kind', 'refund')->sole();
    expect((int) DB::table('money_entries')->where('journal_id', $journal->id)->sum('amount_minor'))->toBe(-80_000)
        ->and(Receipt::query()->where('payment_id', $payment)->value('amount_minor'))->toBe(100_000)
        ->and(Refund::query()->whereNotNull('number')->count())->toBe(1);
    setLedgerBalanced();

    // Audit trail of the whole story, in order.
    expect(accMAudit('refund.'))->toBe(['refund.requested', 'refund.started', 'refund.failed', 'refund.requested', 'refund.executed'])
        ->and(DB::table('state_transitions')->where('machine', 'M-12')->where('subject_id', $first)->orderBy('id')->pluck('transition')->all())
        ->toBe(['T00', 'T01', 'T04']);
});
