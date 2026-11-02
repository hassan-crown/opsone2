<?php

/*
| ZATCA e-invoicing flows (ADR-0033) on the finance fixture: automatic invoices on the first confirmed payment (phase 1
| before onboarding, signed + reported/cleared after), the hash chain, credit notes from refunds, idempotent
| submission, retries, rejection, immutability, the owner's control panel and its authorization.
*/

use App\EInvoicing\EInvoiceListeners;
use App\EInvoicing\EInvoiceSubmitter;
use App\EInvoicing\InvoiceBlocked;
use App\EInvoicing\InvoiceIssuer;
use App\EInvoicing\MissingInvoices;
use App\EInvoicing\Zatca\Api\FakeZatcaClient;
use App\EInvoicing\Zatca\InvoiceHasher;
use App\EInvoicing\Zatca\InvoiceSigner;
use App\EInvoicing\Zatca\Tlv;
use App\EInvoicing\ZatcaOnboarding;
use App\Enums\CustomerKind;
use App\Finance\Settlement\RefundExecuted;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaDevice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\OutboxMessage;
use App\Notifications\Outbox;
use App\Notifications\OutboxDeliverer;
use App\Notifications\OutboxStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    FakeZatcaClient::reset();
    $this->f = financeFixture();
    $this->year = now()->format('Y');
});

function zatcaEnable(array $overrides = []): ZatcaSettings
{
    return ZatcaSettings::query()->create($overrides + [
        'enabled' => true, 'enabled_at' => now()->subMinute(), 'environment' => 'fake',
        'seller_name_ar' => 'مؤسسة المطابخ الحديثة', 'seller_name_en' => 'Modern Kitchens', 'vat_number' => '399999999900003',
        'commercial_registration' => '1010010000', 'street' => 'طريق الملك فهد', 'building_number' => '1234',
        'district' => 'العليا', 'city' => 'الرياض', 'postal_code' => '12211', 'branch_name' => 'الرياض', 'industry' => 'مطابخ',
    ]);
}

function zatcaOnboard(object $f): ZatcaDevice
{
    return app(ZatcaOnboarding::class)->start($f->owner, '123456');
}

function zatcaBusiness(Customer $customer, bool $address = true): void
{
    $customer->forceFill([
        'kind' => CustomerKind::Organization, 'vat_number' => '300000000000003', 'commercial_registration' => '1010000000',
        'national_address' => $address ? ['street' => 'Olaya St', 'building_number' => '1111', 'additional_number' => null, 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12212'] : null,
    ])->save();
}

function zatcaInvoiceOf(object $f): ?EInvoice
{
    return EInvoice::query()->where('order_invoice_key', $f->order->id)->first();
}

describe('phase 1 (generation, before onboarding)', function () {
    it('issues nothing while e-invoicing is off', function () {
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '575');

        expect(EInvoice::query()->count())->toBe(0);
    });

    it('issues the order\'s simplified invoice on the first confirmed payment, once', function () {
        zatcaEnable();
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '575');
        finPay($this->f->finance, $this->f->order, '575');

        $invoice = zatcaInvoiceOf($this->f);
        expect(EInvoice::query()->count())->toBe(1)
            ->and($invoice->number)->toBe("INV-{$this->year}-000001")
            ->and($invoice->type)->toBe(EInvoice::SIMPLIFIED)
            ->and($invoice->kind)->toBe(EInvoice::KIND_INVOICE)
            ->and($invoice->total_minor)->toBe(115_000)
            ->and($invoice->vat_minor)->toBe(15_000)
            ->and($invoice->net_minor)->toBe(100_000)
            ->and($invoice->payment_means_code)->toBe('30')
            ->and($invoice->signed)->toBeFalse()
            ->and($invoice->submission_status)->toBe(EInvoice::NOT_REQUIRED)
            ->and($invoice->icv)->toBe(1)
            ->and($invoice->previous_hash)->toBe(InvoiceHasher::FIRST_PIH)
            ->and($invoice->hash)->toBe(InvoiceHasher::hash($invoice->xml))
            ->and(Tlv::decode($invoice->qr))->toBe([1 => 'مؤسسة المطابخ الحديثة', 2 => '399999999900003', 3 => $invoice->issue_date->format('Y-m-d').'T'.$invoice->issue_time, 4 => '1150.00', 5 => '150.00'])
            ->and(OutboxMessage::query()->where('channel', 'zatca')->count())->toBe(0)
            ->and(AuditEvent::query()->where('action', 'einvoice.issued')->count())->toBe(1);
    });

    it('issues a standard invoice to a VAT-registered organization, and waits for its national address', function () {
        zatcaEnable();
        zatcaBusiness($this->f->order->customer, address: false);
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');

        expect(zatcaInvoiceOf($this->f))->toBeNull()
            ->and(app(MissingInvoices::class)->orders()->pluck('id')->all())->toBe([$this->f->order->id]);

        zatcaBusiness($this->f->order->customer->refresh());
        expect(app(MissingInvoices::class)->sweep())->toBe(['issued' => 1, 'blocked' => []]);

        $invoice = zatcaInvoiceOf($this->f);
        expect($invoice->type)->toBe(EInvoice::STANDARD)
            ->and($invoice->buyer['vat_number'])->toBe('300000000000003')
            ->and($invoice->buyer['postal_code'])->toBe('12212')
            ->and($invoice->xml)->toContain('name="0100000"')
            ->and(app(MissingInvoices::class)->orders())->toHaveCount(0);
    });

    it('keeps an issued invoice immutable: no edit, no delete', function () {
        zatcaEnable();
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');
        $invoice = zatcaInvoiceOf($this->f);

        expect(fn () => $invoice->forceFill(['total_minor' => 1])->save())->toThrow(LogicException::class)
            ->and(fn () => $invoice->refresh()->forceFill(['xml' => '<x/>'])->save())->toThrow(LogicException::class)
            ->and(fn () => $invoice->refresh()->delete())->toThrow(LogicException::class)
            ->and($invoice->refresh()->total_minor)->toBe(115_000);
    });
});

describe('onboarding', function () {
    it('onboards with the fake gateway: CSR, compliance CSID, six compliance checks, production CSID', function () {
        zatcaEnable();
        $device = zatcaOnboard($this->f);

        expect($device->status)->toBe(ZatcaDevice::ACTIVE)
            ->and($device->compliance_checks)->toHaveCount(6)
            ->and(array_unique(array_values($device->compliance_checks)))->toBe(['passed'])
            ->and($device->production_token)->not->toBeNull()
            ->and($device->live_key)->toBe(1)
            ->and(collect(FakeZatcaClient::$calls)->pluck('call')->all())->toBe(['compliance', ...array_fill(0, 6, 'compliance_check'), 'production'])
            // The key is encrypted at rest and never serialized.
            ->and(DB::table('zatca_devices')->value('private_key'))->not->toContain('PRIVATE KEY')
            ->and($device->toArray())->not->toHaveKeys(['private_key', 'compliance_secret', 'production_secret'])
            ->and(AuditEvent::query()->where('action', 'zatca.onboarded')->count())->toBe(1);
    });

    it('keeps the device at its step when ZATCA refuses, and resumes without a new OTP', function () {
        zatcaEnable();
        $bad = app(ZatcaOnboarding::class)->start($this->f->owner, '000000');
        expect($bad->status)->toBe(ZatcaDevice::DRAFT)->and($bad->last_error)->toContain('compliance_csid');

        FakeZatcaClient::queue(200, 200, 400);
        $device = zatcaOnboard($this->f);
        expect($device->status)->toBe(ZatcaDevice::COMPLIANCE)
            ->and($device->last_error)->toContain('compliance_check:standard_credit')
            ->and($bad->refresh()->status)->toBe(ZatcaDevice::RETIRED);

        $device = app(ZatcaOnboarding::class)->complete($device, $this->f->owner);
        expect($device->status)->toBe(ZatcaDevice::ACTIVE)->and($device->last_error)->toBeNull();
    });
});

describe('phase 2 (signed, reported / cleared)', function () {
    it('signs, reports a simplified invoice through the outbox and keeps the hash chain', function () {
        zatcaEnable();
        $device = zatcaOnboard($this->f);
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');

        $invoice = zatcaInvoiceOf($this->f);
        expect($invoice->signed)->toBeTrue()
            ->and($invoice->device_id)->toBe($device->id)
            ->and($invoice->submission_status)->toBe(EInvoice::REPORTED)
            ->and($invoice->submission_attempts)->toBe(1)
            ->and(InvoiceSigner::verify($invoice->xml)['valid'])->toBeTrue()
            ->and(array_keys(Tlv::decode($invoice->qr)))->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9])
            ->and(collect(FakeZatcaClient::$calls)->last())->toBe(['call' => 'report', 'uuid' => $invoice->uuid])
            ->and(AuditEvent::query()->whereIn('action', ['einvoice.signed', 'einvoice.submitted', 'einvoice.response'])->orderBy('id')->pluck('action')->all())
            ->toBe(['einvoice.signed', 'einvoice.submitted', 'einvoice.response']);

        // Two notes later the chain still links each document to the one before it.
        $credit = app(InvoiceIssuer::class)->creditNote($invoice, 10_000, 'خصم بعد التسليم', null, $this->f->owner);
        $debit = app(InvoiceIssuer::class)->debitNote($invoice, 5_000, 'بند إضافي', $this->f->owner);
        $chain = EInvoice::query()->where('device_id', $device->id)->orderBy('icv')->get();
        expect($chain->pluck('icv')->all())->toBe([1, 2, 3])
            ->and($chain[0]->previous_hash)->toBe(InvoiceHasher::FIRST_PIH)
            ->and($chain[1]->previous_hash)->toBe($chain[0]->hash)
            ->and($chain[2]->previous_hash)->toBe($chain[1]->hash)
            ->and($credit->number)->toBe("CRN-{$this->year}-000001")
            ->and($debit->number)->toBe("DBN-{$this->year}-000001")
            ->and($credit->refresh()->submission_status)->toBe(EInvoice::REPORTED)
            ->and(InvoiceIssuer::creditable($invoice))->toBe(115_000 - 10_000 + 5_000);
    });

    it('clears a standard invoice and keeps the cleared XML for its QR', function () {
        zatcaEnable();
        zatcaOnboard($this->f);
        zatcaBusiness($this->f->order->customer);
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');

        $invoice = zatcaInvoiceOf($this->f);
        expect($invoice->type)->toBe(EInvoice::STANDARD)
            ->and($invoice->submission_status)->toBe(EInvoice::CLEARED)
            ->and($invoice->cleared_xml)->toBe($invoice->xml)
            ->and(collect(FakeZatcaClient::$calls)->last()['call'])->toBe('clear')
            ->and(array_keys(Tlv::decode($invoice->qr)))->toBe([1, 2, 3, 4, 5, 6, 7, 8]);
    });

    it('submits once even when the outbox message is delivered again or enqueued twice', function () {
        zatcaEnable();
        zatcaOnboard($this->f);
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');
        $invoice = zatcaInvoiceOf($this->f);
        $message = OutboxMessage::query()->where('channel', 'zatca')->sole();
        $reports = fn () => collect(FakeZatcaClient::$calls)->where('call', 'report')->count();

        expect($reports())->toBe(1);
        app(EInvoiceSubmitter::class)->deliver(new OutboxMessage(['payload' => ['e_invoice_id' => $invoice->id]]));
        expect(app(Outbox::class)->enqueueEInvoice($invoice->id, 'zatca:'.$invoice->uuid))->toBeNull()
            ->and($reports())->toBe(1)
            ->and($message->refresh()->status)->toBe(OutboxStatus::Sent)
            ->and($invoice->refresh()->submission_attempts)->toBe(1);
    });

    it('retries transport failures with backoff, fails after the last attempt, and the owner retries', function () {
        zatcaEnable();
        zatcaOnboard($this->f);
        FakeZatcaClient::queue(...array_fill(0, OutboxDeliverer::MAX_ATTEMPTS, 503));
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');
        $invoice = zatcaInvoiceOf($this->f);
        $message = OutboxMessage::query()->where('channel', 'zatca')->sole();

        for ($i = 1; $i < OutboxDeliverer::MAX_ATTEMPTS; $i++) {
            expect($invoice->refresh()->submission_status)->toBe(EInvoice::PENDING);
            $this->travel(3)->hours();
            app(OutboxDeliverer::class)->deliver($message->id);
        }
        expect($message->refresh()->status)->toBe(OutboxStatus::Dead)
            ->and($invoice->refresh()->submission_status)->toBe(EInvoice::FAILED)
            ->and($invoice->zatca_response['error'])->toBe('retries_exhausted');

        $this->actingAs($this->f->owner)->post(route('einvoicing.retry', $invoice))->assertRedirect();
        expect($invoice->refresh()->submission_status)->toBe(EInvoice::REPORTED)
            ->and(AuditEvent::query()->where('action', 'einvoice.retry_requested')->count())->toBe(1);
    });

    it('records a ZATCA rejection for good and never resends it', function () {
        zatcaEnable();
        zatcaOnboard($this->f);
        FakeZatcaClient::queue(400);
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');
        $invoice = zatcaInvoiceOf($this->f);

        expect($invoice->submission_status)->toBe(EInvoice::REJECTED)
            ->and($invoice->zatca_response['errors'][0]['code'])->toBe('FAKE-400');
        $this->actingAs($this->f->owner)->post(route('einvoicing.retry', $invoice));
        expect($invoice->refresh()->submission_status)->toBe(EInvoice::REJECTED);
    });
});

describe('credit notes from refunds', function () {
    it('issues one credit note per executed refund, linked to the invoice and the refund', function () {
        zatcaEnable();
        finAgree($this->f, '1150');
        $payment = finPay($this->f->finance, $this->f->order, '1150');
        $refund = setPaid($this->f->finance, setRefund($this->f->finance, $this->f->order, $payment, '230'));

        $invoice = zatcaInvoiceOf($this->f);
        $credit = EInvoice::query()->where('refund_id', $refund->id)->sole();
        expect($credit->kind)->toBe(EInvoice::KIND_CREDIT)
            ->and($credit->original_invoice_id)->toBe($invoice->id)
            ->and($credit->total_minor)->toBe(23_000)
            ->and($credit->vat_minor)->toBe(3_000)
            ->and($credit->reason)->toContain($refund->number)
            ->and($credit->xml)->toContain('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>')
            ->and($credit->xml)->toContain('<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>'.$invoice->number.'</cbc:ID>');

        // A replayed event changes nothing.
        app(EInvoiceListeners::class)->onRefund(new RefundExecuted($refund->id, $this->f->order->id, $payment->id, 23_000, (string) $refund->number));
        expect(EInvoice::query()->where('kind', EInvoice::KIND_CREDIT)->count())->toBe(1);
    });

    it('never credits more than what is left on the invoice', function () {
        zatcaEnable();
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');
        $invoice = zatcaInvoiceOf($this->f);

        $note = app(InvoiceIssuer::class)->creditNote($invoice, 200_000, 'إلغاء كامل', null, $this->f->owner);
        expect($note->total_minor)->toBe(115_000)
            ->and(fn () => app(InvoiceIssuer::class)->creditNote($invoice, 100, 'مرة أخرى', null, $this->f->owner))
            ->toThrow(InvoiceBlocked::class);
    });
});

describe('control panel and authorization', function () {
    it('lets only the owner open and change the settings, and never sends a secret', function () {
        zatcaEnable();
        zatcaOnboard($this->f);

        $this->actingAs($this->f->manager)->get(route('einvoicing.edit'))->assertForbidden();
        $this->actingAs($this->f->finance)->put(route('einvoicing.update'), ['enabled' => false, 'environment' => 'fake'])->assertForbidden();
        $this->actingAs($this->f->manager)->post(route('einvoicing.onboard'), ['otp' => '123456'])->assertForbidden();

        $page = $this->actingAs($this->f->owner)->get(route('einvoicing.edit'))->assertOk();
        $page->assertInertia(fn ($p) => $p->component('account/settings-einvoicing')->where('device.status', 'active')->where('settings.vat_number', '399999999900003'));
        $device = ZatcaDevice::live();
        expect($page->getContent())->not->toContain('PRIVATE KEY')
            ->not->toContain((string) $device->production_secret)
            ->not->toContain((string) $device->compliance_secret);
    });

    it('validates the profile and refuses to enable an incomplete one', function () {
        $this->actingAs($this->f->owner)->put(route('einvoicing.update'), [
            'enabled' => true, 'environment' => 'fake', 'vat_number' => '123', 'postal_code' => '1',
        ])->assertSessionHasErrors(['vat_number', 'postal_code']);
        $this->actingAs($this->f->owner)->put(route('einvoicing.update'), ['enabled' => true, 'environment' => 'fake', 'seller_name_ar' => 'منشأة'])
            ->assertSessionHasErrors(['enabled']);
        $this->actingAs($this->f->owner)->put(route('einvoicing.update'), ['enabled' => false, 'environment' => 'production'])
            ->assertSessionHasErrors(['environment']);

        $this->actingAs($this->f->owner)->put(route('einvoicing.update'), [
            'enabled' => true, 'environment' => 'fake', 'seller_name_ar' => 'منشأة', 'vat_number' => '399999999900003',
            'commercial_registration' => '1010010000', 'street' => 'شارع', 'building_number' => '1234', 'district' => 'حي',
            'city' => 'الرياض', 'postal_code' => '12211',
        ])->assertSessionHasNoErrors();
        expect(ZatcaSettings::current()->enabled)->toBeTrue()->and(ZatcaSettings::current()->enabled_at)->not->toBeNull();
    });

    it('serves the PDF and XML to the owner and to P-37 on the order, not to others', function () {
        zatcaEnable();
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');
        $invoice = zatcaInvoiceOf($this->f);

        $pdf = $this->actingAs($this->f->owner)->get(route('einvoicing.pdf', $invoice))->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
        $this->actingAs($this->f->finance)->get(route('einvoicing.xml', $invoice))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->actingAs($this->f->executor)->get(route('einvoicing.pdf', $invoice))->assertForbidden();
        $this->actingAs($this->f->supervisor)->get(route('einvoicing.xml', $invoice))->assertForbidden();
    });

    it('lets the owner issue a manual credit note with a reason', function () {
        zatcaEnable();
        finAgree($this->f, '1150');
        finPay($this->f->finance, $this->f->order, '1150');
        $invoice = zatcaInvoiceOf($this->f);

        $this->actingAs($this->f->finance)->post(route('einvoicing.notes', $invoice), ['kind' => 'credit_note', 'amount' => '100', 'reason' => 'خصم'])->assertForbidden();
        $this->actingAs($this->f->owner)->post(route('einvoicing.notes', $invoice), ['kind' => 'credit_note', 'amount' => '100'])->assertSessionHasErrors(['reason']);
        $this->actingAs($this->f->owner)->post(route('einvoicing.notes', $invoice), ['kind' => 'credit_note', 'amount' => '100.50', 'reason' => 'خصم تسوية'])->assertSessionHasNoErrors();

        expect(EInvoice::query()->where('original_invoice_id', $invoice->id)->sole()->total_minor)->toBe(10_050);
    });
});
