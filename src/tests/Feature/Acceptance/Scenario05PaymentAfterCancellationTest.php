<?php

/**
 * Binding scenario 5 «دفع إلكتروني يصل بعد قبول الإلغاء» («اختبارات ترابط ملزمة»): a collection, its receipt and a
 * settlement only — the order stays cancelled and no work or new sale starts.
 *
 * Traces: SRS-INT-08 (one settlement follow-up per order with its effects from their sources; an operationally
 * cancelled order stays cancelled even with a pending refund; a late event keeps the truth and reopens the follow-up
 * without restarting work), P03, P05, P07; M-05, M-11-T08, ADR-0020 / ADR-0023.
 *
 * Actors over HTTP: a guest buyer on the public store who identifies with the checkout code (Q-48 a), starts the
 * payment and cancels it from the pending page; Moyasar through `/webhooks/payments/moyasar` (test secret, faked);
 * the expiry job through `store:expire-checkouts`.
 */

use App\Enums\PaymentStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Commissions\OrderSale;
use App\Models\Customer;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\Settlement\SettlementItem;
use App\Models\StateTransition;
use App\Models\UserNotification;
use App\Store\CheckoutStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/AcceptancePortalHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake(); // the checkout code stays readable in the outbox
    $this->s = storeFixture(5);
    $this->variant = $this->s->f->variant;
});

it('a payment arriving after the buyer\'s cancellation is collected with one receipt and a settlement refund; the order stays cancelled and nothing restarts', function () {
    accPFreshBrowser();
    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 2])->assertRedirect('/ar/store/cart');
    $this->post('/ar/store/checkout/code', ['name' => 'نورة', 'mobile' => '0551112233'])->assertRedirect('/ar/store/checkout');
    $this->post('/ar/store/checkout/verify', ['code' => storeOtpCode()])->assertRedirect('/ar/store/checkout');
    $buyer = Customer::query()->where('mobile', '+966551112233')->sole();
    $this->post('/ar/store/checkout', ['idempotency_key' => 'acc5-start-00001'])->assertRedirectContains('https://checkout.moyasar.test/invoices/');

    $checkout = accPCheckoutOf($buyer);
    $order = $checkout->order;
    expect($checkout->total_minor)->toBe(9000)
        ->and($order->status)->toBe(ReadyOrderStatus::AwaitingPayment->value)
        ->and(storeAvailable($this->s))->toBe('3');

    // The buyer cancels from the pending page; the provider is asked first (still unpaid), then the order is cancelled.
    $this->post("/ar/store/checkout/{$checkout->reference}/cancel")->assertRedirect("/ar/store/checkout/{$checkout->reference}");
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Abandoned)
        ->and($order->refresh()->status)->toBe(ReadyOrderStatus::Cancelled->value)
        ->and(StockReservation::query()->where('order_id', $order->id)->sole()->status->value)->toBe('released')
        ->and(storeAvailable($this->s))->toBe('5');
    $orders = Order::query()->count();
    $transitions = StateTransition::query()->where('machine', 'M-05')->pluck('transition')->all();

    // The bank completes the payment afterwards; the notice arrives twice.
    $paymentId = FakeMoyasar::pay($checkout);
    moyasarWebhook($paymentId)->assertOk();
    moyasarWebhook($paymentId)->assertOk();
    // Time passes: the expiry job finds nothing to release or post.
    $this->travel(45)->minutes();
    $this->artisan('store:expire-checkouts')->assertSuccessful();

    $receipt = Receipt::query()->sole();
    $item = SettlementItem::query()->where('order_id', $order->id)->sole();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::NeedsAttention)
        ->and($checkout->attention_reason)->toBe('paid_after_cancellation')
        // Collection + receipt: the money is real and recorded once.
        ->and($checkout->payment->status)->toBe(PaymentStatus::Confirmed)
        ->and(Payment::query()->count())->toBe(1)
        ->and($receipt->order_id)->toBe($order->id)
        ->and($receipt->amount_minor)->toBe(9000)
        ->and($receipt->number)->toBe('RC-'.now()->format('Y').'-000001')
        // Settlement only: one refund item on the order's follow-up for the full amount, no refund executed by itself.
        ->and($item->kind)->toBe('refund')
        ->and($item->target_minor)->toBe(9000)
        ->and(DB::table('refunds')->count())->toBe(0)
        // The order stays cancelled; no new sale, reservation, order or confirmation starts.
        ->and($order->refresh()->status)->toBe(ReadyOrderStatus::Cancelled->value)
        ->and(StockReservation::query()->where('status', 'active')->count())->toBe(0)
        ->and(storeAvailable($this->s))->toBe('5')
        ->and(OrderSale::query()->count())->toBe(0)
        ->and(Order::query()->count())->toBe($orders)
        ->and(StateTransition::query()->where('machine', 'M-05')->pluck('transition')->all())->toBe($transitions)
        ->and(StateTransition::query()->where('machine', 'M-05')->where('transition', 'T03')->exists())->toBeFalse()
        ->and(StateTransition::query()->where('machine', 'M-11')->where('transition', 'T08')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'store.checkout_paid')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'store.checkout_needs_attention')->where('subject_id', $order->id)->count())->toBe(1)
        ->and(UserNotification::query()->where('user_id', $this->s->f->sales->id)->where('type', 'store.needs_attention')->where('target_id', $order->id)->count())->toBe(1);
    stockJournalMatches();

    // The buyer sees the cancelled order and its state, never a "paid / confirmed" order.
    $this->get("/ar/store/checkout/{$checkout->reference}")->assertOk()->assertSee($order->number);
});
