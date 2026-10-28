<?php

/**
 * Settlement endpoints and screens (docs/p6-finance-api.md «6c»): FinRefund, FinRefundExec, FinCorrect, FinDispute,
 * FinDisputeResolve, FinInvoices, FinInvoiceLink. Every write needs an idempotency key; amounts never reach a viewer
 * without P-37 and executors never reach settlement at all.
 */

use App\Models\Finance\ExternalDocument;
use App\Models\Finance\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/SettlementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    finAgree($this->f, '1000');
    $this->payment = finPay($this->f->finance, $this->f->order, '1000');
    $this->year = now()->format('Y');
});

function setPdf(string $body = 'x'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('p.pdf', "%PDF-1.4\n".$body);
}

it('renders the settlement on the order finance page, with limits for finance and statuses only for a viewer without P-37', function () {
    setRefund($this->f->finance, $this->f->order, $this->payment, '300');
    $this->actingAs($this->f->finance)->get("/orders/{$this->f->order->id}/finance")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('orders/finance')
            ->where('settlement.payments.0.refundable_minor', 70_000)
            ->where('settlement.refunds.0.amount_minor', 30_000)
            ->where('settlement.can.execute_refund', true)
            ->where('settlement.can.documents', true));

    $this->actingAs($this->f->supervisor)->get("/orders/{$this->f->order->id}/finance")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('orders/finance')
            ->where('settlement.amounts_visible', false)
            ->missing('settlement.payments.0.refundable_minor')
            ->missing('settlement.refunds.0.amount_minor')
            ->where('settlement.refunds.0.status', 'requested'));

    $this->actingAs($this->f->executor)->post("/orders/{$this->f->order->id}/finance/refunds", [], ['Accept' => 'application/json'])->assertStatus(422);
    $this->actingAs($this->f->executor)->get('/finance/invoices')->assertForbidden();
});

it('requests, executes with proof and answers with the voucher over HTTP once per key; a refund over the limit is a 409', function () {
    $request = fn (string $key, string $amount) => $this->actingAs($this->f->sales)->postJson("/orders/{$this->f->order->id}/finance/refunds", [
        'payment_id' => $this->payment->id, 'amount' => $amount, 'method' => 'transfer', 'reason' => 'زيادة غير مخصصة.',
        'idempotency_key' => $key, 'lock_version' => finVersion($this->f->order),
    ]);
    $created = $request('refund-key-0001', '450')->assertCreated();
    $refund = Refund::query()->findOrFail((int) $created->json('refund_id'));
    $request('refund-key-0002', '600')->assertStatus(409)->assertJson(['condition' => 'not_refundable']);
    $this->actingAs($this->f->sales)->postJson("/orders/{$this->f->order->id}/finance/refunds", [
        'payment_id' => $this->payment->id, 'amount' => '1', 'method' => 'transfer', 'reason' => 'x', 'lock_version' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

    $confirm = fn () => $this->actingAs($this->f->finance)->post("/finance/refunds/{$refund->id}/confirm", [
        'reference' => 'TRX-450', 'proof' => setPdf('same'), 'idempotency_key' => 'refund-pay-0001', 'lock_version' => $refund->lock_version,
    ], ['Accept' => 'application/json']);
    expect($confirm()->assertOk()->json('number'))->toBe("RF-{$this->year}-000001");
    $again = $confirm()->assertOk();
    expect($again->json('finance.summary.collected_minor'))->toBe(55_000)
        ->and($again->json('settlement.refunds.0.status'))->toBe('executed');

    $this->actingAs($this->f->sales)->post("/finance/refunds/{$refund->id}/confirm", [
        'reference' => 'TRX', 'proof' => setPdf(), 'idempotency_key' => 'refund-pay-0002',
    ], ['Accept' => 'application/json'])->assertForbidden();
});

it('corrects a payment, opens and resolves a dispute and links an external invoice over HTTP', function () {
    $correction = $this->actingAs($this->f->finance)->post("/finance/payments/{$this->payment->id}/correction", [
        'order_id' => $this->f->order->id, 'kind' => 'amend', 'reason' => 'المبلغ الصحيح 900.', 'amount' => '900', 'method' => 'cash',
        'paid_on' => now()->toDateString(), 'idempotency_key' => 'correct-key-01', 'lock_version' => finVersion($this->f->order),
    ], ['Accept' => 'application/json'])->assertCreated();
    expect($correction->json('number'))->toBe("CR-{$this->year}-000001")
        ->and($correction->json('finance.summary.collected_minor'))->toBe(90_000)
        ->and($correction->json('settlement.corrections.0.replacement_receipt'))->toBe("RC-{$this->year}-000002");

    $dispute = $this->actingAs($this->f->finance)->postJson("/orders/{$this->f->order->id}/finance/disputes", [
        'payment_id' => $correction->json('settlement.payments.0.id'), 'subject' => 'duplicate', 'amount' => '900',
        'responsible_id' => $this->f->finance->id, 'reason' => 'يدعي العميل تحويلًا مكررًا.', 'idempotency_key' => 'dispute-key-1',
        'lock_version' => finVersion($this->f->order),
    ])->assertCreated();
    $id = (int) $dispute->json('dispute_id');
    $this->actingAs($this->f->finance)->post("/finance/disputes/{$id}/resolve", [
        'outcome' => 'rejected', 'resolution' => 'كشف البنك يظهر تحويلًا واحدًا.', 'proof' => setPdf(), 'idempotency_key' => 'dispute-res-1',
        'lock_version' => 1,
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('settlement.disputes.0.status', 'resolved');

    $this->actingAs($this->f->finance)->post("/orders/{$this->f->order->id}/finance/documents/link", [
        'kind' => 'tax_invoice', 'event' => 'order', 'source' => 'Qoyod', 'number' => 'INV-2026-0398', 'issued_on' => now()->toDateString(),
        'amount' => '900', 'file' => setPdf(), 'idempotency_key' => 'doc-link-0001',
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('settlement.documents.0.status', 'attached')
        ->assertJsonPath('settlement.customer_tax.missing.0', 'vat_number');
    $document = ExternalDocument::query()->sole();
    $this->actingAs($this->f->finance)->postJson("/finance/documents/{$document->id}/accept", ['idempotency_key' => 'doc-acc-0001', 'lock_version' => $document->lock_version])
        ->assertOk()->assertJsonPath('settlement.documents.0.status', 'accepted');

    $this->actingAs($this->f->finance)->get('/finance/invoices')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('finance/invoices')
            ->where('documents.0.number', 'INV-2026-0398')
            ->where('documents.0.order.id', $this->f->order->id));
});
