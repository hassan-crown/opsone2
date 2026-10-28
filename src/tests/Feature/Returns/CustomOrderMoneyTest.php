<?php

/**
 * Regression for the P6 change of ADR-0019, made-to-order side: admitting ready orders on the manual money path left
 * custom-order finance untouched — counter payment, refund execution and the online payment start all still work.
 */

use App\Agreements\CustomerActor;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Finance\FinanceLock;
use App\Finance\Gateway\GatewayPayments;
use App\Finance\Gateway\GatewayRegistry;
use App\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';
require_once __DIR__.'/ReturnTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('attachments'));

it('leaves made-to-order finance unchanged: every path still takes the custom order', function () {
    $f = financeFixture();
    finAgree($f, '1000');
    $owner = $f->org->owner;
    $payment = finPay($owner, $f->order, '600');
    $refund = setRefund($owner, $f->order, $payment, '100');
    setPaid($owner, $refund);
    $customer = new CustomerActor($f->order->customer_id, $f->order->customer->mobile);
    app(GatewayRegistry::class)->add(readyGateway());
    ['payment' => $online] = app(GatewayPayments::class)->begin($customer, $f->order->refresh(), Money::of(5000), 'readypay', 'https://shop.test/back');

    expect($payment->status)->toBe(PaymentStatus::Confirmed)
        ->and($refund->refresh()->status)->toBe(RefundStatus::Executed)
        ->and($online->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(FinanceLock::versionOf($f->order))->toBeGreaterThan(1);
});
