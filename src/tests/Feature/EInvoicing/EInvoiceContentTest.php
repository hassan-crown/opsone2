<?php

/*
| What an e-invoice carries (ADR-0033): a store order's invoice follows its paid checkout (coupon included, never the
| order lines priced again) and every discount reaches the frozen lines before VAT for the PDF, while the totals stay
| the sums of the rounded lines the customer agreed to or paid.
*/

use App\EInvoicing\EInvoicePdf;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Store\CouponAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Store/StoreTestHelpers.php';
require_once __DIR__.'/../Finance/FinanceTestHelpers.php';

uses(RefreshDatabase::class);

function zatcaContentEnable(): void
{
    ZatcaSettings::query()->create([
        'enabled' => true, 'enabled_at' => now()->subMinute(), 'environment' => 'fake',
        'seller_name_ar' => 'مؤسسة أوبس ون', 'vat_number' => '399999999900003', 'commercial_registration' => '1010010000',
        'street' => 'طريق الملك فهد', 'building_number' => '1234', 'district' => 'العليا', 'city' => 'الرياض',
        'postal_code' => '12211', 'branch_name' => 'الرياض', 'industry' => 'مطابخ',
    ]);
}

it('invoices a store order from its paid checkout, coupon included, with the discount on the line', function () {
    $this->s = storeFixture(5);
    zatcaContentEnable();
    app(CouponAdmin::class)->save($this->s->f->org->owner, [
        'code' => 'SAVE10', 'title_ar' => 'خصم عشرة', 'kind' => 'percent', 'value' => '10', 'starts_at' => now()->subHour()->toDateTimeString(),
    ]);
    ['checkout' => $checkout] = storeStart(storeBuyer(), [$this->s->f->variant->id => 2], 'save10');
    moyasarWebhook(FakeMoyasar::pay($checkout))->assertOk();
    $checkout->refresh();

    $invoice = EInvoice::query()->where('order_invoice_key', $checkout->order_id)->sole();
    expect($checkout->discount_minor)->toBeGreaterThan(0)
        ->and($invoice->total_minor)->toBe($checkout->total_minor)
        ->and($invoice->vat_minor)->toBe($checkout->pricing['totals']['vat_minor'])
        ->and($invoice->net_minor)->toBe($checkout->pricing['totals']['net_minor'])
        ->and($invoice->lines[0]['discount_minor'])->toBe($checkout->discount_minor)
        ->and($invoice->lines[0]['gross_minor'])->toBe($checkout->total_minor);
});

it('carries an agreement discount before VAT on each line and shows it on the PDF', function () {
    $f = financeFixture();
    zatcaContentEnable();
    // 10% off 1150 + 575 (VAT-inclusive): 1035 + 517.50 after discount.
    $quote = agrSend($f->sales, agrQuote($f->sales, $f->order, agrQuoteData(['type' => 'percent', 'value' => '10'], [finLine('مطبخ', '1150'), finLine('تركيب', '575', false)])));
    agrApprove($f->owner, $quote);
    finPay($f->finance, $f->order, '500');

    $invoice = EInvoice::query()->where('order_invoice_key', $f->order->id)->sole();
    $lines = $invoice->lines;
    expect($invoice->total_minor)->toBe(155_250)
        ->and(array_sum(array_column($lines, 'gross_minor')))->toBe(155_250)
        // net before discount 1000 / 500, after 900 / 450: the pre-VAT discount is 100 and 50.
        ->and($lines[0]['net_minor'])->toBe(90_000)
        ->and($lines[0]['discount_minor'])->toBe(10_000)
        ->and($lines[1]['discount_minor'])->toBe(5_000)
        ->and(substr(app(EInvoicePdf::class)->render($invoice), 0, 4))->toBe('%PDF');
});
