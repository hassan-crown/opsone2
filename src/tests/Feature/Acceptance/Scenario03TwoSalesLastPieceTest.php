<?php

/**
 * Binding scenario 3 «بيعان على آخر قطعة» («اختبارات ترابط ملزمة»): exactly one reservation is confirmed; the other sale
 * is refused (or corrected) before any money for an unavailable piece, and a late success keeps the money for handling.
 *
 * Traces: SRS-FUL-02 (two orders never hold the same balance; the cart reserves nothing), SRS-WEB-06 (30-minute hold,
 * late results: confirm the hold or a refund, never an oversale), P07; M-05-T02..T07, M-24, ADR-0023.
 *
 * Actors over HTTP: both buyers on the public store (`/ar/store/cart`, `/ar/store/checkout`) signed in on the customer
 * guard; Moyasar through `/webhooks/payments/moyasar` with the test secret (FakeMoyasar, no network); time passing
 * through `store:expire-checkouts`.
 */

use App\Enums\PaymentStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Commissions\OrderSale;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Inventory\StockReservation;
use App\Models\Settlement\SettlementItem;
use App\Models\Store\StoreCheckout;
use App\Models\UserNotification;
use App\Store\CheckoutStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/AcceptancePortalHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = storeFixture(1); // one piece left at A1, price 45.00 (VAT inclusive)
    $this->variant = $this->s->f->variant;
    $this->first = storeBuyer();
    $this->second = storeBuyer();
});

it('two sales on the last piece: one reservation, the other is refused before any invoice or money', function () {
    // The second buyer's cart holds the piece first: a cart reserves nothing (FUL-02).
    accPCustomer($this->second);
    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 1])->assertRedirect('/ar/store/cart');
    expect(storeAvailable($this->s))->toBe('1')->and(StockReservation::query()->count())->toBe(0);

    accPStoreCheckout($this->first, $this->variant->id, 1, 'acc3-first-00001')->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    $first = accPCheckoutOf($this->first);
    expect($first->total_minor)->toBe(4500)
        ->and($first->order->status)->toBe(ReadyOrderStatus::AwaitingPayment->value)
        ->and(StockReservation::query()->where('status', 'active')->sole()->order_id)->toBe($first->order_id)
        ->and(StockReservation::query()->sole()->quantity_milli)->toBe(1000)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0']);

    // The second sale: refused at confirmation, before any invoice or payment exists for it.
    accPCustomer($this->second);
    $this->from('/ar/store/checkout')->post('/ar/store/checkout', ['idempotency_key' => 'acc3-second-0001'])
        ->assertRedirect('/ar/store/checkout')->assertSessionHasErrors();
    expect(StoreCheckout::query()->count())->toBe(1)
        ->and(StoreCheckout::query()->where('customer_id', $this->second->id)->exists())->toBeFalse()
        ->and(FakeMoyasar::$created)->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(StockReservation::query()->count())->toBe(1);

    // The first buyer pays: one confirmed order, one receipt, the single reservation kept for delivery.
    moyasarWebhook(FakeMoyasar::pay($first))->assertOk();
    expect($first->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($first->order->refresh()->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and(Receipt::query()->sole()->amount_minor)->toBe(4500)
        ->and(Receipt::query()->sole()->order_id)->toBe($first->order_id)
        ->and(StockReservation::query()->where('status', 'active')->count())->toBe(1)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0'])
        ->and(DB::table('audit_events')->where('action', 'store.checkout_started')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'store.checkout_paid')->where('subject_id', $first->order_id)->count())->toBe(1);
    stockJournalMatches();
});

it('a late success after the piece was sold to the other buyer keeps the money for a refund, never oversold', function () {
    accPStoreCheckout($this->first, $this->variant->id, 1, 'acc3-late-000001')->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    $late = accPCheckoutOf($this->first);

    // The window ends without money: the provider is re-read, then the piece is released.
    $this->travel(31)->minutes();
    $this->artisan('store:expire-checkouts')->assertSuccessful();
    expect($late->refresh()->status)->toBe(CheckoutStatus::Expired)
        ->and($late->order->status)->toBe(ReadyOrderStatus::Cancelled->value)
        ->and($late->payment->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(storeAvailable($this->s))->toBe('1');

    // The other buyer takes the piece and pays.
    accPStoreCheckout($this->second, $this->variant->id, 1, 'acc3-other-00001')->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    $other = accPCheckoutOf($this->second);
    moyasarWebhook(FakeMoyasar::pay($other))->assertOk();
    expect($other->refresh()->status)->toBe(CheckoutStatus::Paid);

    // The first buyer's payment succeeds late (still inside the invoice's grace).
    moyasarWebhook(FakeMoyasar::pay($late))->assertOk();

    $item = SettlementItem::query()->where('order_id', $late->order_id)->sole();
    expect($late->refresh()->status)->toBe(CheckoutStatus::NeedsAttention)
        ->and($late->attention_reason)->toBe('late_payment_no_stock')
        ->and($late->order->refresh()->status)->toBe(ReadyOrderStatus::NeedsAttention->value)
        // The money is real and kept: one confirmed payment with its own receipt, waiting for a refund.
        ->and($late->payment->refresh()->status)->toBe(PaymentStatus::Confirmed)
        ->and(Receipt::query()->where('order_id', $late->order_id)->sole()->amount_minor)->toBe(4500)
        ->and($item->kind)->toBe('refund')
        ->and($item->target_minor)->toBe(4500)
        // Never oversold: the only active reservation is the paid buyer's.
        ->and(StockReservation::query()->where('status', 'active')->sole()->order_id)->toBe($other->order_id)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '1', 'reserved' => '1', 'available' => '0'])
        ->and(Receipt::query()->count())->toBe(2)
        ->and(OrderSale::query()->where('order_id', $late->order_id)->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('action', 'store.checkout_needs_attention')->where('subject_id', $late->order_id)->count())->toBe(1)
        // The order's assignee (store follower) is told.
        ->and(UserNotification::query()->where('user_id', $this->s->f->sales->id)->where('type', 'store.needs_attention')->where('target_id', $late->order_id)->count())->toBe(1);
    stockJournalMatches();
});
