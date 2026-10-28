<?php

use App\Actions\StateMachines\M08Document\T00UploadDesign;
use App\Actions\StateMachines\M16Appointment\T01SendToCustomer;
use App\Models\Customer;
use App\Models\Finance\Receipt;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Portal\PortalSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';

/**
 * Customer workspace fixture: two customers of branch A with workspace login enabled, each with a custom order holding
 * an approved agreement, a design awaiting their approval, a transfer proof under review, a receipt and an appointment
 * awaiting their confirmation. Customer A's records are `$f->recA`, B's `$f->recB`.
 */
function portalFixture(): object
{
    Storage::fake('attachments');
    $f = financeFixture();
    $f->tech = $f->org->member('executor', [$f->org->a]);
    $f->a = $f->inA;
    $f->a->forceFill(['portal_login_enabled' => true])->save();
    $f->b = Customer::factory()->create(['branch_id' => $f->org->a->id, 'portal_login_enabled' => true]);
    $f->orderA = $f->order;
    $f->orderB = openCustomOrder($f->sales, $f->b);
    $f->recA = portalRecords($f, $f->orderA);
    $f->recB = portalRecords($f, $f->orderB);
    // The fixture acted as staff; every test starts signed out of both guards.
    Auth::forgetGuards();

    return $f;
}

function portalRecords(object $f, Order $order): object
{
    $quote = agrSend($f->sales, agrQuote($f->sales, $order, agrQuoteData(lines: [finLine('مطبخ', '10000')])));
    agrApprove($f->owner, $quote);
    $design = app(T00UploadDesign::class)->handle($f->manager, $order, agrPdf('kitchen.pdf'), 'تصميم المطبخ', [], null, agrKey());
    $design = agrSend($f->sales, $design);
    $proof = finProof($order->refresh(), '1000.00', 'FT'.$order->id.'001');
    finPay($f->finance, $order->refresh(), '2000.00');
    $appointment = propose($f->sales, $order, [$f->tech->id], '2026-11-0'.(3 + $order->id % 5).' 09:00', '2026-11-0'.(3 + $order->id % 5).' 10:00');
    $appointment = app(T01SendToCustomer::class)->handle($f->sales, $appointment);

    return (object) [
        'order' => $order->refresh(),
        'quote' => $quote->refresh(),
        'design' => $design->refresh(),
        'proof' => $proof->refresh(),
        'receipt' => Receipt::query()->where('order_id', $order->id)->latest('id')->firstOrFail(),
        'appointment' => $appointment->refresh(),
    ];
}

/**
 * Signs the customer in through the real OTP flow (request → code from the outbox → verify).
 */
function portalSignIn(Customer $customer): void
{
    test()->post('/portal/login', ['identifier' => $customer->mobile])->assertRedirect('/portal/login/verify');
    test()->post('/portal/login/verify', ['code' => lastOtpCode()])->assertRedirect('/portal');
}

/**
 * The code of the latest sign-in message (payload is encrypted at rest; the model decrypts it).
 */
function lastOtpCode(): string
{
    $message = OutboxMessage::query()->where('idempotency_key', 'like', 'portal-otp:%')->latest('id')->firstOrFail();
    $text = (string) ($message->payload['text'] ?? implode(' ', $message->payload['lines'] ?? []));
    preg_match('/\b(\d{6})\b/', $text, $m);

    return $m[1];
}

/**
 * Signs the customer in on the customer guard only; unlike actingAs() the default (staff) guard is not switched.
 */
function actingAsCustomer(Customer $customer): void
{
    Auth::guard(PortalSession::GUARD)->setUser($customer);
    session([PortalSession::STAMP => $customer->refresh()->portal_session_version]);
}

/**
 * @return array<string, mixed>
 */
function portalProofForm(string $amount = '500.00'): array
{
    return ['amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT'.random_int(100000, 999999), 'file' => agrPdf('transfer.pdf', (string) random_int(1, 999999))];
}
