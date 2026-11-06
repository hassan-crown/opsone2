<?php

/*
| ADR-0046: the customer sees where to transfer — the facility's default account only, as an allow-list — on the pay
| page, the order's schedule, quotes/contracts (portal and PDF), the invoice PDF and the due reminder. Nothing shows
| without an active account.
*/

use App\Agreements\Signing\DocumentSheet;
use App\EInvoicing\EInvoicePdf;
use App\Finance\CollectionNotices;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\FacilityBankAccount;
use App\Models\Finance\OrderDue;
use App\Models\OutboxMessage;
use App\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/PortalTestHelpers.php';

uses(RefreshDatabase::class);

function bankAccount(array $overrides = []): FacilityBankAccount
{
    return FacilityBankAccount::query()->create([
        'bank_name' => 'مصرف الراجحي',
        'beneficiary_name' => 'مؤسسة الركن الحديث',
        'iban' => 'SA0380000000608010167519',
        'account_number' => '608010167519',
        'swift_code' => 'RJHISARI',
        'currency' => 'SAR',
        'transfer_note' => 'أرسل الإيصال بعد التحويل',
        'is_default' => true,
        'is_active' => true,
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->f = portalFixture();
    Queue::fake();
});

it('shows the default account, amount and reference on the pay page, as an allow-list', function () {
    bankAccount();
    // Another active account and a disabled one are never shown.
    bankAccount(['bank_name' => 'البنك الأهلي', 'iban' => 'SA1310000000000000000123', 'is_default' => false, 'transfer_note' => 'حساب ثانٍ']);
    bankAccount(['bank_name' => 'بنك معطّل', 'iban' => 'SA5520000000000000000999', 'is_default' => false, 'is_active' => false]);
    actingAsCustomer($this->f->a);

    $response = $this->get("/portal/orders/{$this->f->orderA->id}/pay")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('portal/pay')
            ->where('order.number', $this->f->orderA->number)
            ->where('bank', [
                'bank_name' => 'مصرف الراجحي',
                'beneficiary_name' => 'مؤسسة الركن الحديث',
                'iban' => 'SA0380000000608010167519',
                'iban_grouped' => 'SA03 8000 0000 6080 1016 7519',
                'account_number' => '608010167519',
                'swift_code' => 'RJHISARI',
                'currency' => 'SAR',
                'transfer_note' => 'أرسل الإيصال بعد التحويل',
            ]));
    $json = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('البنك الأهلي')->not->toContain('بنك معطّل')->not->toContain('updated_by')->not->toContain('is_default');

    $this->get("/portal/orders/{$this->f->orderA->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('bank.iban', 'SA0380000000608010167519'));
    $this->get("/portal/documents/{$this->f->recA->quote->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('bank.beneficiary_name', 'مؤسسة الركن الحديث'));
});

it('hides the section when the facility has no active account', function () {
    bankAccount(['is_active' => false, 'is_default' => false]);
    actingAsCustomer($this->f->a);

    $this->get("/portal/orders/{$this->f->orderA->id}/pay")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('bank', null));
    $this->get("/portal/orders/{$this->f->orderA->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('bank', null));
    expect(DocumentSheet::build($this->f->recA->quote, 'ar')->view['bank'])->toBeNull();
});

it('keeps another customer\'s pay page out of reach', function () {
    bankAccount();
    actingAsCustomer($this->f->a);

    $this->get("/portal/orders/{$this->f->orderB->id}/pay")->assertNotFound();
});

it('prints the transfer section on the quote/contract PDF with the order as reference', function () {
    bankAccount();
    $sheet = DocumentSheet::build($this->f->recA->quote, 'ar');
    $html = view('pdf.document', ['v' => $sheet->view])->render();

    expect($sheet->view['bank']['iban'])->toBe('SA03 8000 0000 6080 1016 7519')
        ->and($html)->toContain('SA03 8000 0000 6080 1016 7519')
        ->toContain('مؤسسة الركن الحديث')
        ->toContain('RJHISARI')
        ->toContain($this->f->orderA->number)
        ->toContain(__('esign.pdf.bank.title', [], 'ar'));

    // A change of account changes the fingerprint, so unsigned PDFs are generated again.
    $before = $sheet->fingerprint;
    FacilityBankAccount::query()->update(['beneficiary_name' => 'شركة الركن الحديث']);
    expect(DocumentSheet::build($this->f->recA->quote, 'ar')->fingerprint)->not->toBe($before);
});

it('prints the transfer section on an invoice PDF', function () {
    bankAccount();
    ZatcaSettings::query()->create([
        'enabled' => true, 'enabled_at' => now()->subMinute(), 'environment' => 'fake',
        'seller_name_ar' => 'مؤسسة أوبس ون', 'vat_number' => '399999999900003', 'commercial_registration' => '1010010000',
        'street' => 'طريق الملك فهد', 'building_number' => '1234', 'district' => 'العليا', 'city' => 'الرياض',
        'postal_code' => '12211', 'branch_name' => 'الرياض', 'industry' => 'مطابخ',
    ]);
    finPay($this->f->finance, $this->f->orderA->refresh(), '500.00');

    $invoice = EInvoice::query()->where('kind', EInvoice::KIND_INVOICE)->latest('id')->firstOrFail();
    expect(EInvoicePdf::html($invoice))->toContain('SA03 8000 0000 6080 1016 7519')->toContain('مؤسسة الركن الحديث');
});

it('adds the transfer details to the due reminder, and nothing without an account', function () {
    $due = OrderDue::query()->where('order_id', $this->f->orderA->id)->orderBy('position')->firstOrFail();
    $notices = app(CollectionNotices::class);
    $text = function (string $period) use ($notices, $due) {
        DB::transaction(fn () => $notices->dueReminder($due, $this->f->orderA, Money::of(100_000), 'soon', $period));
        $messages = OutboxMessage::query()->where('idempotency_key', 'like', "due:{$due->id}:soon:{$period}%")->get();
        expect($messages)->not->toBeEmpty();

        // SMS text, WhatsApp's SMS fallback and the mail lines alike.
        return (string) json_encode($messages->map(fn (OutboxMessage $m) => $m->payload)->all(), JSON_UNESCAPED_UNICODE);
    };

    expect($text('p1'))->not->toContain('IBAN');
    bankAccount();
    expect($text('p2'))->toContain('SA03 8000 0000 6080 1016 7519')->toContain($this->f->orderA->number)->toContain('مؤسسة الركن الحديث');
});
