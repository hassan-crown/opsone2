<?php

/**
 * Integrated acceptance — «جاهز متزامن» (SRS «قبول النظام المتكامل») and the binding test «بيعان على آخر قطعة»
 * («اختبارات ترابط ملزمة»): a reservation never exceeds what is available, an ended checkout window is settled from
 * the provider before anything is released, and a successful payment after the window never oversells — it
 * re-reserves when the stock is still there, otherwise the money is kept for a refund follow-up.
 *
 * Traces: SRS-FUL-02 (one reservation per unit, two orders never hold the same balance), SRS-WEB-05 (availability
 * re-checked at confirmation), SRS-WEB-06 (30-minute hold, late results processed, no release while a payment may be
 * pending), P07; ADR-0023 (store and payments), M-05-T02..T07.
 *
 * Every business action runs over HTTP as its actor: the buyer through the customer session on `/ar/store/...`, the
 * provider through the Moyasar webhook `/webhooks/payments/moyasar` (faked as in tests/Feature/Store), the expiry
 * through `store:expire-checkouts`. Fixtures (facility, catalogue, stock, store settings) come from the store helpers.
 */

use App\Enums\PaymentStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Customer;
use App\Models\Finance\GatewaySession;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\Settlement\SettlementItem;
use App\Models\StateTransition;
use App\Models\Store\StoreCheckout;
use App\Store\CheckoutStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Store/StoreTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = storeFixture(1); // the last unit: one piece at A1
    $this->variant = $this->s->f->variant;
    $this->first = storeBuyer();
    $this->second = storeBuyer();
});

it('two buyers on the last unit: one checkout reserves it, the other is refused before any invoice or payment', function () {
    accI05Checkout($this->first, $this->variant->id, 1, 'acc-i05-first-0001')
        ->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    $first = StoreCheckout::query()->sole();

    accI05Checkout($this->second, $this->variant->id, 1, 'acc-i05-second-001')
        ->assertRedirect()->assertSessionHasErrors();

    expect(StoreCheckout::query()->count())->toBe(1)
        ->and($first->customer_id)->toBe($this->first->id)
        ->and($first->status)->toBe(CheckoutStatus::PendingPayment)
        ->and($first->total_minor)->toBe(4500)
        ->and($first->order->status)->toBe(ReadyOrderStatus::AwaitingPayment->value)
        // the refused attempt left nothing behind: no order, no reservation, no invoice, no payment
        ->and(Order::query()->where('kind', 'ready')->count())->toBe(1)
        ->and(StockReservation::query()->count())->toBe(1)
        ->and(StockReservation::query()->sole()->quantity_milli)->toBe(1000)
        ->and(FakeMoyasar::$created)->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(0)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0'])
        ->and(DB::table('audit_events')->where('action', 'store.checkout_started')->count())->toBe(1);

    // The winner pays: one receipt, the unit stays reserved for delivery, nothing oversold.
    moyasarWebhook(FakeMoyasar::pay($first))->assertOk();
    expect($first->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($first->order->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and(Receipt::query()->where('order_id', $first->order_id)->sole()->amount_minor)->toBe(4500)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0']);
    accI05NeverNegative();
    stockJournalMatches();
});

it('a checkout asking for more than is available is refused before payment; the buyer can retry with what is there', function () {
    accI05Checkout($this->first, $this->variant->id, 2, 'acc-i05-over-00001')->assertRedirect()->assertSessionHasErrors();

    expect(StoreCheckout::query()->count())->toBe(0)
        ->and(FakeMoyasar::$created)->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(storeAvailable($this->s))->toBe('1');

    // Corrected quantity (the stale cart line replaced with what is available): the checkout proceeds.
    $this->put("/ar/store/cart/{$this->variant->id}", ['quantity' => 1])->assertRedirect('/ar/store/cart');
    $this->post('/ar/store/checkout', ['idempotency_key' => 'acc-i05-over-00002'])->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    expect(StoreCheckout::query()->sole()->total_minor)->toBe(4500)
        ->and(storeAvailable($this->s))->toBe('0');
});

it('an ended window re-reads the provider before releasing: unreachable keeps the hold, unpaid releases it', function () {
    accI05Checkout($this->first, $this->variant->id, 1, 'acc-i05-timeout-01')->assertRedirect();
    $checkout = StoreCheckout::query()->sole();
    $invoice = accI05InvoiceId($checkout);

    // Before the 30 minutes end nothing is released.
    $this->travel(29)->minutes();
    $this->artisan('store:expire-checkouts')->assertSuccessful();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::PendingPayment)->and(storeAvailable($this->s))->toBe('0');

    // Provider unreachable at the end of the window: never a faked failure, the unit stays held.
    FakeMoyasar::$down = true;
    $this->travel(2)->minutes();
    $this->artisan('store:expire-checkouts')->assertSuccessful();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::PendingPayment)
        ->and($checkout->provider_failures)->toBe(1)
        ->and(StockReservation::query()->sole()->status->value)->toBe('active')
        ->and(storeAvailable($this->s))->toBe('0');

    // Provider back, invoice unpaid: it was asked (GET invoice) and only then the stock and order were released.
    FakeMoyasar::$down = false;
    $this->artisan('store:expire-checkouts')->assertSuccessful();
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/invoices/'.$invoice));
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Expired)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Cancelled->value)
        ->and(StockReservation::query()->sole()->status->value)->toBe('released')
        ->and($checkout->payment->status)->toBe(PaymentStatus::PendingConfirmation) // the invoice may still be paid
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '1', 'reserved' => '0', 'available' => '1'])
        ->and(StateTransition::query()->where('machine', 'M-05')->where('transition', 'T04')->value('reason'))->toBe('reservation_expired')
        ->and(DB::table('audit_events')->where('action', 'store.checkout_released')->count())->toBe(1);
    stockJournalMatches();
});

it('a payment made but never notified is found at expiry and confirms instead of releasing', function () {
    accI05Checkout($this->first, $this->variant->id, 1, 'acc-i05-silent-001')->assertRedirect();
    $checkout = StoreCheckout::query()->sole();
    FakeMoyasar::pay($checkout); // no webhook

    $this->travel(31)->minutes();
    $this->artisan('store:expire-checkouts')->assertSuccessful();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and(Receipt::query()->where('order_id', $checkout->order_id)->sole()->amount_minor)->toBe(4500)
        ->and(StockReservation::query()->sole()->status->value)->toBe('active')
        ->and(storeAvailable($this->s))->toBe('0')
        ->and(DB::table('audit_events')->where('action', 'store.checkout_released')->count())->toBe(0);

    // The second buyer now finds nothing to reserve.
    accI05Checkout($this->second, $this->variant->id, 1, 'acc-i05-silent-002')->assertSessionHasErrors();
    expect(StoreCheckout::query()->count())->toBe(1);
    stockJournalMatches();
});

it('late success while the unit is still there: the money is kept and the order re-reserved and confirmed once', function () {
    accI05Checkout($this->first, $this->variant->id, 1, 'acc-i05-late-00001')->assertRedirect();
    $checkout = StoreCheckout::query()->sole();
    $this->travel(31)->minutes();
    $this->artisan('store:expire-checkouts')->assertSuccessful();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Expired)->and(storeAvailable($this->s))->toBe('1');

    $paymentId = FakeMoyasar::pay($checkout);
    moyasarWebhook($paymentId)->assertOk();
    moyasarWebhook($paymentId)->assertOk(); // repeated notice: no second receipt or reservation

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($checkout->order->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and($checkout->payment->status)->toBe(PaymentStatus::Confirmed)
        ->and(Receipt::query()->where('order_id', $checkout->order_id)->count())->toBe(1)
        ->and(StockReservation::query()->where('order_id', $checkout->order_id)->where('status', 'active')->count())->toBe(1)
        ->and(SettlementItem::query()->where('order_id', $checkout->order_id)->count())->toBe(0)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0'])
        ->and(StateTransition::query()->where('machine', 'M-05')->whereIn('transition', ['T06', 'T07'])->pluck('transition')->all())->toBe(['T06', 'T07']);
    accI05NeverNegative();
    stockJournalMatches();
});

it('بيعان على آخر قطعة: the unit is sold to the second buyer after the first window ended; the first late success is kept for a refund, never oversold', function () {
    accI05Checkout($this->first, $this->variant->id, 1, 'acc-i05-race-00001')->assertRedirect();
    $late = StoreCheckout::query()->sole();
    accI05Checkout($this->second, $this->variant->id, 1, 'acc-i05-race-00002')->assertSessionHasErrors(); // held by the first

    $this->travel(31)->minutes();
    $this->artisan('store:expire-checkouts')->assertSuccessful();
    expect($late->refresh()->status)->toBe(CheckoutStatus::Expired)->and(storeAvailable($this->s))->toBe('1');

    accI05Checkout($this->second, $this->variant->id, 1, 'acc-i05-race-00003')->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    $other = StoreCheckout::query()->where('customer_id', $this->second->id)->sole();
    moyasarWebhook(FakeMoyasar::pay($other))->assertOk();
    expect($other->refresh()->status)->toBe(CheckoutStatus::Paid);

    // The first buyer's payment succeeds late at the provider.
    moyasarWebhook(FakeMoyasar::pay($late))->assertOk();

    $item = SettlementItem::query()->where('order_id', $late->order_id)->sole();
    expect($late->refresh()->status)->toBe(CheckoutStatus::NeedsAttention)
        ->and($late->attention_reason)->toBe('late_payment_no_stock')
        ->and($late->order->status)->toBe(ReadyOrderStatus::NeedsAttention->value)
        ->and($late->payment->status)->toBe(PaymentStatus::Confirmed) // the money is real and kept
        ->and($item->kind)->toBe('refund')
        ->and($item->target_minor)->toBe(4500)
        ->and(Receipt::query()->where('order_id', $other->order_id)->sole()->amount_minor)->toBe(4500)
        ->and(StockReservation::query()->where('status', 'active')->count())->toBe(1)
        ->and(StockReservation::query()->where('status', 'active')->sole()->order_id)->toBe($other->order_id)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0'])
        ->and(DB::table('audit_events')->where('action', 'store.checkout_needs_attention')->where('subject_id', $late->order_id)->count())->toBe(1);
    accI05NeverNegative();
    stockJournalMatches();
});

/**
 * The buyer's own checkout over HTTP: signed in on the customer guard, the variant put in the session cart, then the
 * checkout start (which redirects to the provider's invoice page or back with errors).
 */
function accI05Checkout(Customer $customer, int $variantId, int $quantity, string $key): TestResponse
{
    Auth::forgetGuards();
    actingAsCustomer($customer);
    test()->put("/ar/store/cart/{$variantId}", ['quantity' => 0])->assertRedirect('/ar/store/cart'); // a fresh cart line
    test()->post('/ar/store/cart', ['variant_id' => $variantId, 'quantity' => $quantity])->assertRedirect('/ar/store/cart');

    return test()->from('/ar/store/checkout')->post('/ar/store/checkout', ['idempotency_key' => $key]);
}

function accI05InvoiceId(StoreCheckout $checkout): string
{
    return (string) GatewaySession::query()->where('payment_id', $checkout->payment_id)->value('provider_ref');
}

function accI05NeverNegative(): void
{
    foreach (StockBalance::query()->get() as $balance) {
        expect($balance->on_hand_milli)->toBeGreaterThanOrEqual(0)
            ->and($balance->reserved_milli)->toBeLessThanOrEqual($balance->on_hand_milli);
    }
}
