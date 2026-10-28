<?php

/**
 * Store checkout and Moyasar payments (P8c, ADR-0023; WEB-05/06, FIN-07, M-05-T02..T07, M-11, P07). The provider is
 * faked over HTTP (FakeMoyasar): no real network call. Money is confirmed only by a verified notification whose
 * payment is re-read from Moyasar, or by the expiry service's own re-read — never by the return page.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Agreements\CustomerActor;
use App\Enums\PaymentStatus;
use App\Enums\ReadyOrderStatus;
use App\Finance\Gateway\GatewayPayments;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\Settlement\SettlementItem;
use App\Models\StateTransition;
use App\Models\Store\StoreCheckout;
use App\Money\Money;
use App\Store\CheckoutStatus;
use App\Store\StoreCheckouts;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/StoreTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = storeFixture(5);
    $this->variant = $this->s->f->variant;
    $this->buyer = storeBuyer();
});

function storeExpire(): void
{
    test()->travel(31)->minutes();
    Artisan::call('store:expire-checkouts');
}

it('scenario 2 (store part): a verified payment confirms the ready order once — one receipt, one allocation — and a repeated notice changes nothing', function () {
    ['checkout' => $checkout, 'url' => $url] = storeStart($this->buyer, [$this->variant->id => 2]);
    $order = $checkout->order;

    expect($url)->toStartWith('https://checkout.moyasar.test/invoices/')
        ->and($checkout->number)->toMatch('/^CK-\d{4}-000001$/')
        ->and($order->number)->toMatch('/^ORD-\d{4}-\d+$/')
        ->and($order->status)->toBe(ReadyOrderStatus::AwaitingPayment->value)
        ->and($order->created_by)->toBeNull()
        ->and($order->assignee_id)->toBe($this->s->f->sales->id)
        ->and($checkout->total_minor)->toBe(9000)
        ->and(StockReservation::query()->where('order_id', $order->id)->sole()->number)->toMatch('/^SR-\d{4}-\d{6}$/')
        ->and(storeAvailable($this->s))->toBe('3')
        ->and(FakeMoyasar::$invoices[array_key_first(FakeMoyasar::$invoices)]['amount'])->toBe(9000)
        ->and(FakeMoyasar::$invoices[array_key_first(FakeMoyasar::$invoices)]['success_url'])->toContain($checkout->reference);

    $paymentId = FakeMoyasar::pay($checkout);
    moyasarWebhook($paymentId)->assertOk();
    moyasarWebhook($paymentId)->assertOk();
    storeExpire(); // the window ends later: nothing to release, nothing posted twice

    $checkout->refresh();
    $receipt = Receipt::query()->where('order_id', $order->id)->sole();
    expect($order->refresh()->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and($checkout->status)->toBe(CheckoutStatus::Paid)
        ->and($checkout->paid_at)->not->toBeNull()
        ->and($checkout->payment->status)->toBe(PaymentStatus::Confirmed)
        ->and($receipt->number)->toMatch('/^RC-\d{4}-\d{6}$/')
        ->and($receipt->amount_minor)->toBe(9000)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(storeAvailable($this->s))->toBe('3')
        ->and(StateTransition::query()->where('machine', 'M-05')->where('transition', 'T03')->count())->toBe(1)
        ->and(StateTransition::query()->where('machine', 'M-11')->where('transition', 'T08')->count())->toBe(1);
    stockJournalMatches();
});

it('the return page never confirms: query strings are ignored and only the buyer may open it', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    actingAsBuyer($this->buyer);

    $this->get("/ar/store/checkout/{$checkout->reference}?id=pay_x&status=paid&message=APPROVED")
        ->assertOk()->assertSee(__('checkout.pending.state.pending_payment', [], 'ar'));

    expect($checkout->order->refresh()->status)->toBe(ReadyOrderStatus::AwaitingPayment->value)
        ->and(Receipt::query()->count())->toBe(0);

    actingAsBuyer(storeBuyer());
    $this->get("/ar/store/checkout/{$checkout->reference}")->assertNotFound();
    $this->post("/ar/store/checkout/{$checkout->reference}/cancel")->assertNotFound();
});

it('a webhook with a wrong secret or a live event is rejected and writes nothing', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    $paymentId = FakeMoyasar::pay($checkout);

    moyasarWebhook($paymentId, secret: 'wrong-secret-0000000000')->assertStatus(400);
    moyasarWebhook($paymentId, live: true)->assertStatus(400);
    $this->call('POST', '/webhooks/payments/moyasar', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);

    expect($checkout->payment->refresh()->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(Receipt::query()->count())->toBe(0)
        ->and($checkout->order->refresh()->status)->toBe(ReadyOrderStatus::AwaitingPayment->value);
});

it('the notification body is never trusted: a forged "paid" for an unpaid payment confirms nothing', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    $paymentId = FakeMoyasar::pay($checkout, 'initiated');

    moyasarWebhook($paymentId)->assertOk()->assertJson(['ignored' => true]);
    moyasarWebhook('pay_unknown')->assertStatus(503); // Moyasar does not know it: retried later, nothing written

    expect($checkout->payment->refresh()->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(Receipt::query()->count())->toBe(0);
});

it('an amount or currency that does not match is not money: the order needs attention and keeps its reservation', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    moyasarWebhook(FakeMoyasar::pay($checkout, amount: $checkout->total_minor - 100))->assertOk();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::NeedsAttention)
        ->and($checkout->attention_reason)->toBe('amount_mismatch')
        ->and($checkout->payment->status)->toBe(PaymentStatus::Unresolved)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::NeedsAttention->value)
        ->and(Receipt::query()->count())->toBe(0)
        ->and(storeAvailable($this->s))->toBe('4');

    $second = storeStart(storeBuyer(), [$this->variant->id => 1])['checkout'];
    moyasarWebhook(FakeMoyasar::pay($second, currency: 'USD'))->assertOk();
    expect($second->refresh()->attention_reason)->toBe('amount_mismatch')->and(Receipt::query()->count())->toBe(0);
});

it('WEB-06: an ended window is settled from Moyasar first, then the stock, coupon use and order are released', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 2]);
    expect(storeAvailable($this->s))->toBe('3');

    $this->travel(29)->minutes();
    Artisan::call('store:expire-checkouts');
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::PendingPayment);

    storeExpire();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Expired)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Cancelled->value)
        ->and(storeAvailable($this->s))->toBe('5')
        ->and(StockReservation::query()->where('order_id', $checkout->order_id)->sole()->status->value)->toBe('released')
        ->and(StateTransition::query()->where('machine', 'M-05')->where('transition', 'T04')->value('reason'))->toBe('reservation_expired')
        // The invoice may still be paid during its grace: the payment stays open until Moyasar settles it.
        ->and($checkout->payment->status)->toBe(PaymentStatus::PendingConfirmation);

    FakeMoyasar::expireInvoice($checkout);
    $this->travel(11)->minutes();
    Artisan::call('store:expire-checkouts');
    expect($checkout->payment->refresh()->status)->toBe(PaymentStatus::Cancelled);
    stockJournalMatches();
});

it('WEB-06: a paid result found at expiry confirms instead of releasing', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    FakeMoyasar::pay($checkout); // the notice never arrived

    storeExpire();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and(Receipt::query()->count())->toBe(1);
});

it('M-05-T04/T05: an unreachable provider never fakes a failure — the reservation stays, then a person is told', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    FakeMoyasar::$down = true;

    storeExpire();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::PendingPayment)
        ->and($checkout->provider_failures)->toBe(1)
        ->and(storeAvailable($this->s))->toBe('4');

    $this->travel(31)->minutes();
    Artisan::call('store:expire-checkouts');
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::NeedsAttention)
        ->and($checkout->attention_reason)->toBe('provider_unreachable')
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::NeedsAttention->value)
        ->and(storeAvailable($this->s))->toBe('4');
});

it('late payment after the window, stock still there: the money is kept and the order re-reserved and confirmed (M-05-T06/T07)', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 2]);
    storeExpire();
    expect(storeAvailable($this->s))->toBe('5');

    moyasarWebhook(FakeMoyasar::pay($checkout))->assertOk();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and(Receipt::query()->where('order_id', $checkout->order_id)->count())->toBe(1)
        ->and(storeAvailable($this->s))->toBe('3')
        ->and(StateTransition::query()->where('machine', 'M-05')->whereIn('transition', ['T06', 'T07'])->pluck('transition')->all())->toBe(['T06', 'T07']);
    stockJournalMatches();
});

it('scenario 3 (late part): late payment when the stock was sold meanwhile is kept for a refund — never oversold', function () {
    ['checkout' => $late] = storeStart($this->buyer, [$this->variant->id => 5]);
    storeExpire();
    ['checkout' => $other] = storeStart(storeBuyer(), [$this->variant->id => 5]);
    moyasarWebhook(FakeMoyasar::pay($other))->assertOk();

    moyasarWebhook(FakeMoyasar::pay($late))->assertOk();

    $item = SettlementItem::query()->where('order_id', $late->order_id)->sole();
    expect($late->refresh()->status)->toBe(CheckoutStatus::NeedsAttention)
        ->and($late->attention_reason)->toBe('late_payment_no_stock')
        ->and($late->order->status)->toBe(ReadyOrderStatus::NeedsAttention->value)
        ->and($late->payment->status)->toBe(PaymentStatus::Confirmed)
        ->and($item->kind)->toBe('refund')
        ->and($item->target_minor)->toBe($late->total_minor)
        ->and(storeAvailable($this->s))->toBe('0')
        ->and((int) StockReservation::query()->where('status', 'active')->sum('quantity_milli'))->toBe(5000);
    stockJournalMatches();
});

it('scenario 5 (store part): a payment that arrives after the buyer cancelled is recorded for settlement only — the order stays cancelled', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    actingAsBuyer($this->buyer);
    $this->post("/ar/store/checkout/{$checkout->reference}/cancel")->assertRedirect();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Abandoned)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Cancelled->value)
        ->and(storeAvailable($this->s))->toBe('5');

    moyasarWebhook(FakeMoyasar::pay($checkout))->assertOk();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::NeedsAttention)
        ->and($checkout->attention_reason)->toBe('paid_after_cancellation')
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Cancelled->value)
        ->and(Receipt::query()->where('order_id', $checkout->order_id)->count())->toBe(1)
        ->and(SettlementItem::query()->where('order_id', $checkout->order_id)->sole()->kind)->toBe('refund')
        ->and(storeAvailable($this->s))->toBe('5');
});

it('cancelling asks Moyasar first: an already paid checkout is confirmed, not released', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    FakeMoyasar::pay($checkout);
    actingAsBuyer($this->buyer);

    $this->post("/ar/store/checkout/{$checkout->reference}/cancel")->assertRedirect();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid);
});

it('a repeated start with the same key returns the same checkout and creates one invoice', function () {
    $key = 'start-key-0001';
    $first = storeStart($this->buyer, [$this->variant->id => 1], key: $key);
    $second = storeStart($this->buyer, [$this->variant->id => 1], key: $key);

    expect($second['checkout']->id)->toBe($first['checkout']->id)
        ->and($second['url'])->toBe($first['url'])
        ->and(StoreCheckout::query()->count())->toBe(1)
        ->and(Order::query()->where('kind', 'ready')->count())->toBe(1)
        ->and(FakeMoyasar::$created)->toBe(1);
});

it('when Moyasar cannot start the payment nothing stays held', function () {
    FakeMoyasar::$down = true;

    expect(fn () => storeStart($this->buyer, [$this->variant->id => 2]))->toThrow(ValidationException::class);

    $checkout = StoreCheckout::query()->sole();
    expect($checkout->status)->toBe(CheckoutStatus::Abandoned)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Cancelled->value)
        ->and($checkout->payment->status)->toBe(PaymentStatus::Cancelled)
        ->and(storeAvailable($this->s))->toBe('5');
});

it('one customer cannot keep the stock held with many open checkouts', function () {
    storeStart($this->buyer, [$this->variant->id => 1]);
    storeStart($this->buyer, [$this->variant->id => 1]);
    expect(fn () => storeStart($this->buyer, [$this->variant->id => 1]))->toThrow(ValidationException::class)
        ->and(StoreCheckout::query()->count())->toBe(2)
        ->and(storeAvailable($this->s))->toBe('3');
});

it('more than the stock is refused before anything is written', function () {
    expect(fn () => storeStart($this->buyer, [$this->variant->id => 6]))->toThrow(ValidationException::class)
        ->and(StoreCheckout::query()->count())->toBe(0)
        ->and(Order::query()->where('kind', 'ready')->count())->toBe(0)
        ->and(storeAvailable($this->s))->toBe('5');
});

it('a declined card while the invoice can still be paid is not a final failure', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    moyasarWebhook(FakeMoyasar::pay($checkout, 'failed'), 'payment_failed')->assertOk()->assertJson(['ignored' => true]);
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::PendingPayment);

    moyasarWebhook(FakeMoyasar::pay($checkout))->assertOk();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid);
});

it('a late notice of a declined attempt never cancels an invoice a later attempt paid', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    $declined = FakeMoyasar::pay($checkout, 'failed');
    $paid = FakeMoyasar::pay($checkout);

    // The failure notice arrives after the invoice was paid (before or after the paid notice).
    moyasarWebhook($declined, 'payment_failed')->assertOk()->assertJson(['ignored' => true]);
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::PendingPayment);
    moyasarWebhook($paid)->assertOk();
    moyasarWebhook($declined, 'payment_failed')->assertOk()->assertJson(['ignored' => true]);
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($checkout->order->refresh()->status)->toBe(ReadyOrderStatus::Confirmed->value);
});

it('a payment outside the app\'s invoices that names a checkout reference in its metadata is not money for it', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    FakeMoyasar::$payments['pay_direct1'] = [
        'id' => 'pay_direct1', 'status' => 'paid', 'amount' => $checkout->total_minor, 'currency' => 'SAR', 'invoice_id' => null,
        'created_at' => now()->toIso8601String(), 'metadata' => ['merchant_reference' => $checkout->payment->reference],
    ];

    moyasarWebhook('pay_direct1')->assertOk()->assertJson(['ignored' => true]);
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::PendingPayment)
        ->and(Receipt::query()->where('order_id', $checkout->order_id)->exists())->toBeFalse();
});

it('FinanceLock: a ready order takes online money only through the store checkout (the portal begin refuses it)', function () {
    ['checkout' => $checkout] = storeStart($this->buyer, [$this->variant->id => 1]);
    $actor = new CustomerActor($this->buyer->id, $this->buyer->mobile);

    expect(fn () => app(GatewayPayments::class)->begin($actor, $checkout->order, Money::of(100), 'moyasar', 'https://x.test'))
        ->toThrow(InvalidTransition::class);
    expect(app(StoreCheckouts::class)->gateway()->key())->toBe('moyasar');
});

it('the webhook route lifts the web group\'s request-forgery check (Laravel skips it under tests, so the route is inspected)', function () {
    $route = app('router')->getRoutes()->getByName('webhooks.payments');

    expect($route->excludedMiddleware())->toContain(PreventRequestForgery::class);
});
