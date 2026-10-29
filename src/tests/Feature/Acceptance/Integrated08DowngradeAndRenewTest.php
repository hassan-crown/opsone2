<?php

/**
 * Integrated acceptance — «تخفيض وتجديد» (SRS «قبول النظام المتكامل»), facility side, and the binding test
 * «تخفيض جاهز مع محاولة دفع سابقة» («اختبارات ترابط ملزمة»): once the installed license no longer carries
 * `ready_goods`, every NEW ready order, ready confirmation and store checkout stops at once; what already exists is
 * still settled (delivered, cancelled with its stock released, a pending store payment applied from its source);
 * custom orders, their data and the history stay intact and readable; a renewal/upgrade license restores ready goods.
 * The license phases after the end date (wind-down: no new work; expired: read only) are covered as implemented.
 *
 * Traces: SRS-SUB-07 (renewal/upgrade by a target license, no new facility), SRS-SUB-08 (removing ready goods stops
 * the store and every new creation/confirmation immediately; existing settlement and custom products remain),
 * SRS-SUB-09/10 (settle the existing, restoring the capability reopens as before), SRS-SUB-12 (grace, 30-day
 * completion of existing work, then reading), SRS-WEB-05, SRS-FUL-02; P09; ADR-0028, ADR-0023.
 *
 * Business actions run over HTTP as their actor (staff via actingAs, buyer via the customer session, provider via
 * the Moyasar webhook). Licenses are swapped with Tests\Support\LicenseFactory (the verified-install seam).
 */

use App\Access\Access;
use App\Access\Authorizer;
use App\Enums\PaymentStatus;
use App\Enums\ReadyOrderStatus;
use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;
use App\Models\Customer;
use App\Models\Delivery\DeliveryNote;
use App\Models\Finance\Receipt;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\Store\StoreCheckout;
use App\Models\User;
use App\Store\CheckoutStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/../Store/StoreTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->s = storeFixture(10);
    $this->owner = $this->s->f->org->owner;
    $this->keeper = $this->s->f->org->member('inventory', [$this->s->f->org->a]);
    $this->variant = $this->s->f->variant;
    $this->customer = $this->s->f->inA;
});

it('downgrade without ready_goods: new ready orders, confirmations and store checkouts stop at once; the existing ones are settled; custom work stays', function () {
    // Before the downgrade: a custom order, two confirmed ready orders, a ready draft and an open store checkout.
    $custom = accI08CustomOrder($this->owner, $this->customer);
    $toDeliver = accI08ConfirmedReady($this, 3);
    $toCancel = accI08ConfirmedReady($this, 2);
    $draft = readyOrder($this->s, 1, $this->owner);
    $buyer = storeBuyer();
    accI08StoreCheckout($buyer, $this->variant->id, 1, 'acc-i08-before-001')->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    $pending = StoreCheckout::query()->sole();
    expect(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '10', 'reserved' => '6', 'available' => '4']);
    $before = ['orders' => Order::query()->count(), 'ready' => Order::query()->where('kind', 'ready')->count(), 'audit' => DB::table('audit_events')->count()];

    accI08Downgrade();

    // New ready work is refused on the server (staff create, draft confirmation) …
    $this->actingAs($this->owner)->postJson('/orders', readyPayload($this->customer, $this->variant, 1))->assertForbidden();
    $this->actingAs($this->owner)->postJson("/orders/{$draft->id}/confirm", ['location_id' => $this->s->la->id, 'lock_version' => $draft->refresh()->lock_version])->assertForbidden();
    // … and the store is gone for buyers: catalogue, cart and checkout.
    Auth::forgetGuards();
    actingAsCustomer(storeBuyer());
    $this->get('/ar/store')->assertNotFound();
    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 1])->assertNotFound();
    $this->post('/ar/store/checkout', ['idempotency_key' => 'acc-i08-after-0001'])->assertNotFound();

    expect(Order::query()->where('kind', 'ready')->count())->toBe($before['ready'])
        ->and($draft->refresh()->status)->toBe(ReadyOrderStatus::Draft->value)
        ->and(StoreCheckout::query()->count())->toBe(1)
        ->and(FakeMoyasar::$created)->toBe(1)
        ->and(StockReservation::query()->where('status', 'active')->count())->toBe(3);

    // Custom work is untouched: still readable and new custom orders still open.
    $this->actingAs($this->owner)->getJson("/orders/{$custom->id}")->assertOk();
    $this->actingAs($this->owner)->get("/orders/{$toDeliver->id}")->assertOk();
    $newCustom = accI08CustomOrder($this->owner, $this->customer);
    expect($newCustom->kind->value)->toBe('custom')->and($custom->refresh()->status)->toBe('in_progress');

    // Existing ready order #1: delivered in full by the inventory officer (stock issued from its reservation).
    $line = (int) $toDeliver->activeLines()->firstOrFail()->id;
    $note = $this->actingAs($this->keeper)->postJson("/orders/{$toDeliver->id}/deliveries", [
        'idempotency_key' => 'acc-i08-dn-draft-1', 'lines' => [['order_line_id' => $line, 'quantity' => '3']],
    ])->assertCreated()->json('note');
    $this->actingAs($this->keeper)->post("/deliveries/{$note['id']}/confirm", [
        'idempotency_key' => 'acc-i08-dn-conf-01', 'lock_version' => $note['lock_version'], 'delivered_on' => now()->toDateString(),
        'receiver_name' => 'خالد', 'proofs' => [UploadedFile::fake()->image('receipt.png', 80, 80)],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('note.status', 'confirmed');
    expect($toDeliver->refresh()->status)->toBe(ReadyOrderStatus::Delivered->value)
        ->and(StockReservation::query()->where('order_id', $toDeliver->id)->sole()->issued_milli)->toBe(3000)
        ->and(DeliveryNote::query()->where('order_id', $toDeliver->id)->sole()->number)->toStartWith('DN-');

    // Existing ready order #2: cancelled, its reservation released.
    $this->actingAs($this->owner)->postJson("/orders/{$toCancel->id}/cancel-remainder", ['reason' => 'تصفية قبل التخفيض'])
        ->assertOk()->assertJsonPath('order.status', 'cancelled');
    expect(StockReservation::query()->where('order_id', $toCancel->id)->sole()->status->value)->toBe('released');

    // The open store checkout is settled from its own source: the provider's verified payment confirms it.
    moyasarWebhook(FakeMoyasar::pay($pending))->assertOk();
    expect($pending->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($pending->order->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and($pending->payment->status)->toBe(PaymentStatus::Confirmed)
        ->and(Receipt::query()->where('order_id', $pending->order_id)->sole()->amount_minor)->toBe(4500);

    // Stock: 3 delivered out, 2 released, 1 still held for the paid store order; nothing negative.
    expect(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '7', 'reserved' => '1', 'available' => '6'])
        ->and(Order::query()->count())->toBe($before['orders'] + 1)
        // history only grows: nothing was deleted or rewritten by the downgrade
        ->and(DB::table('audit_events')->count())->toBeGreaterThan($before['audit'])
        ->and(DB::table('audit_events')->where('action', 'order.created')->where('subject_id', $custom->id)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'store.checkout_started')->where('subject_id', $pending->order_id)->count())->toBe(1);
    stockJournalMatches();
});

it('renewal/upgrade restores ready goods: staff ready orders and the store checkout work again, the old records are unchanged', function () {
    $custom = accI08CustomOrder($this->owner, $this->customer);
    $old = accI08ConfirmedReady($this, 2);

    accI08Downgrade();
    $this->actingAs($this->owner)->postJson('/orders', readyPayload($this->customer, $this->variant, 1))->assertForbidden();
    $this->get('/ar/store')->assertNotFound();

    // The upgrade/renewal target license (newer, with ready_goods again) is installed.
    accI08Install(['capabilities' => ['ready_goods', 'branch_comparisons'], 'issued_at' => now('UTC')->addMinute()->toIso8601ZuluString()]);
    expect(app(CurrentLicense::class)->allows('ready_goods'))->toBeTrue();

    $this->actingAs($this->owner)->postJson('/orders', readyPayload($this->customer, $this->variant, 1))->assertCreated();
    $renewed = Order::query()->where('kind', 'ready')->latest('id')->firstOrFail();
    $this->actingAs($this->owner)->postJson("/orders/{$renewed->id}/confirm", ['location_id' => $this->s->la->id, 'lock_version' => $renewed->lock_version])
        ->assertOk()->assertJsonPath('order.status', 'confirmed');

    Auth::forgetGuards();
    $this->get('/ar/store')->assertOk();
    accI08StoreCheckout(storeBuyer(), $this->variant->id, 1, 'acc-i08-renew-0001')->assertRedirectContains('https://checkout.moyasar.test/invoices/');

    expect(StoreCheckout::query()->count())->toBe(1)
        ->and($old->refresh()->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and($custom->refresh()->status)->toBe('in_progress')
        ->and(Order::query()->where('kind', 'ready')->count())->toBe(3)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '10', 'reserved' => '4', 'available' => '6']);
    stockJournalMatches();
});

it('wind-down after the end date: no new work (custom or ready, store closed) while existing ready orders can still be delivered', function () {
    $custom = accI08CustomOrder($this->owner, $this->customer);
    $order = accI08ConfirmedReady($this, 2);

    accI08Ended(10);
    expect(app(CurrentLicense::class)->refresh()->status)->toBe(LicenseStatus::WindDown);

    $this->actingAs($this->owner)->postJson('/orders', readyPayload($this->customer, $this->variant, 1))->assertForbidden();
    $this->actingAs($this->owner)->postJson('/orders', customPayload($this->customer))->assertForbidden();
    Auth::forgetGuards();
    $this->get('/ar/store')->assertNotFound();

    // Completing what exists is allowed during the wind-down (SUB-12).
    $line = (int) $order->activeLines()->firstOrFail()->id;
    $note = $this->actingAs($this->keeper)->postJson("/orders/{$order->id}/deliveries", [
        'idempotency_key' => 'acc-i08-wd-draft-1', 'lines' => [['order_line_id' => $line, 'quantity' => '2']],
    ])->assertCreated()->json('note');
    $this->actingAs($this->keeper)->post("/deliveries/{$note['id']}/confirm", [
        'idempotency_key' => 'acc-i08-wd-conf-01', 'lock_version' => $note['lock_version'], 'delivered_on' => now()->toDateString(),
        'receiver_name' => 'خالد', 'proofs' => [UploadedFile::fake()->image('receipt.png', 80, 80)],
    ], ['Accept' => 'application/json'])->assertOk();

    expect($order->refresh()->status)->toBe(ReadyOrderStatus::Delivered->value)
        ->and(Order::query()->count())->toBe(2)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '8', 'reserved' => '0', 'available' => '8']);
    $this->actingAs($this->owner)->getJson("/orders/{$custom->id}")->assertOk();
    stockJournalMatches();
});

it('expired: everything stays readable, but no ready order is created, confirmed or delivered and the store is closed', function () {
    $custom = accI08CustomOrder($this->owner, $this->customer);
    $order = accI08ConfirmedReady($this, 2);
    $draft = readyOrder($this->s, 1, $this->owner);

    accI08Ended(60);
    expect(app(CurrentLicense::class)->refresh()->status)->toBe(LicenseStatus::Expired);

    $this->actingAs($this->owner)->get('/orders')->assertOk();
    $this->actingAs($this->owner)->getJson("/orders/{$custom->id}")->assertOk();
    $this->actingAs($this->owner)->getJson("/orders/{$order->id}")->assertOk();

    $this->actingAs($this->owner)->postJson('/orders', readyPayload($this->customer, $this->variant, 1))->assertForbidden();
    $this->actingAs($this->owner)->postJson("/orders/{$draft->id}/confirm", ['location_id' => $this->s->la->id, 'lock_version' => $draft->refresh()->lock_version])->assertForbidden();
    $line = (int) $order->activeLines()->firstOrFail()->id;
    $this->actingAs($this->keeper)->postJson("/orders/{$order->id}/deliveries", [
        'idempotency_key' => 'acc-i08-ex-draft-1', 'lines' => [['order_line_id' => $line, 'quantity' => '1']],
    ])->assertForbidden();
    Auth::forgetGuards();
    $this->get('/ar/store')->assertNotFound();

    expect(Order::query()->count())->toBe(3)
        ->and(DeliveryNote::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id))->toBe(['on_hand' => '10', 'reserved' => '2', 'available' => '8']);
});

/**
 * A license replaced by one without `ready_goods` (the downgrade target), issued after the current one.
 */
function accI08Downgrade(): void
{
    accI08Install(['capabilities' => ['branch_comparisons'], 'issued_at' => now('UTC')->toIso8601ZuluString()]);
    expect(app(CurrentLicense::class)->allows('ready_goods'))->toBeFalse();
}

/**
 * Installs a license as the next request of a real deployment sees it. LicenseFactory::install() forgets the scoped
 * container instances, but the `Access` facade keeps its cached Authorizer (and with it the previous license) for the
 * rest of the PHP process; a web request in production always starts with a fresh one, so the test drops that cache.
 *
 * @param  array<string, mixed>  $overrides
 */
function accI08Install(array $overrides): void
{
    LicenseFactory::install($overrides);
    Access::clearResolvedInstance(Authorizer::class);
}

/**
 * The current plan whose term ended `$days` days ago (grace 7, wind-down 30, then expired).
 */
function accI08Ended(int $days): void
{
    $now = CarbonImmutable::now('UTC');
    LicenseFactory::place([
        'starts_at' => $now->subDays($days + 365)->toIso8601ZuluString(),
        'ends_at' => $now->subDays($days)->toIso8601ZuluString(),
        'issued_at' => $now->toIso8601ZuluString(),
    ]);
}

function accI08CustomOrder(User $actor, Customer $customer): Order
{
    test()->actingAs($actor)->postJson('/orders', customPayload($customer))->assertCreated();

    return Order::query()->where('kind', 'custom')->latest('id')->firstOrFail();
}

/**
 * A ready order created and confirmed (reserved at A1) over HTTP by the owner.
 */
function accI08ConfirmedReady(object $t, int $quantity): Order
{
    $order = readyOrder($t->s, $quantity, $t->owner);
    $t->actingAs($t->owner)->postJson("/orders/{$order->id}/confirm", ['location_id' => $t->s->la->id, 'lock_version' => $order->lock_version])
        ->assertOk()->assertJsonPath('order.status', 'confirmed');

    return $order->refresh();
}

function accI08StoreCheckout(Customer $customer, int $variantId, int $quantity, string $key): TestResponse
{
    Auth::forgetGuards();
    actingAsCustomer($customer);
    test()->put("/ar/store/cart/{$variantId}", ['quantity' => 0])->assertRedirect('/ar/store/cart');
    test()->post('/ar/store/cart', ['variant_id' => $variantId, 'quantity' => $quantity])->assertRedirect('/ar/store/cart');

    return test()->from('/ar/store/checkout')->post('/ar/store/checkout', ['idempotency_key' => $key]);
}
