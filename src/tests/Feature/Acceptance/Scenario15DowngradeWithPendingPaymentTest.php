<?php

/**
 * Binding scenario 15 «تخفيض جاهز مع محاولة دفع سابقة» («اختبارات ترابط ملزمة»), facility side: removing the ready-goods
 * capability stops every new store sale at once; the payment attempt already started, the refunds and the purchases
 * are still settled; custom products' materials and the general commission statements stay.
 *
 * Traces: SRS-SUB-08 (removing ready goods stops the store and every new creation/confirmation as soon as the owner's
 * request is recorded; existing settlement stays; custom products and their materials stay available), SRS-SUB-09
 * (orders, purchases, delivery, money, refunds and pending operations are cleared before the target, without deleting
 * history), P09; ADR-0023 (StoreGate), ADR-0028 (license modes).
 *
 * The central app (owner's request, issuing the smaller license) is out of scope: the downgrade reaches the facility
 * as a signed license installed with `license:install`. Every other step runs over HTTP as its actor: buyers on
 * `/ar/store`, Moyasar through the webhook (test secret, faked), the owner/inventory officer on the staff screens.
 */

use App\Enums\OrderKind;
use App\Enums\PaymentStatus;
use App\Enums\ReadyOrderStatus;
use App\Licensing\CurrentLicense;
use App\Models\Finance\Receipt;
use App\Models\Order;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Supplier;
use App\Models\Settlement\SettlementItem;
use App\Models\Store\StoreCheckout;
use App\Models\Store\StoreLink;
use App\Store\CheckoutStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/AcceptancePortalHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->s = storeFixture(5);
    $this->variant = $this->s->f->variant;
    $this->owner = $this->s->f->org->owner;
    $this->keeper = $this->s->keeper; // inventory officer of branch A
    $this->supplier = Supplier::query()->create(['name' => ['ar' => 'مورد المقابض', 'en' => 'Handles supplier']]);

    // Before the downgrade: (1) a buyer started a payment that is still pending;
    accPStoreCheckout($this->pendingBuyer = storeBuyer(), $this->variant->id, 1, 'acc15-pending-001')->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    $this->pending = accPCheckoutOf($this->pendingBuyer);
    // (2) another buyer's payment arrived after they cancelled: money kept for a refund on the settlement follow-up;
    accPStoreCheckout($this->refundBuyer = storeBuyer(), $this->variant->id, 2, 'acc15-refund-0001')->assertRedirect();
    $this->toRefund = accPCheckoutOf($this->refundBuyer);
    $this->post("/ar/store/checkout/{$this->toRefund->reference}/cancel")->assertRedirect();
    moyasarWebhook(FakeMoyasar::pay($this->toRefund))->assertOk();
    // (3) an open purchase of the ready variant and of the custom products' material, confirmed by the inventory officer.
    accPStaff($this->keeper);
    foreach ([['ready_variant', $this->variant->id, '10', 'acc15-po-ready'], ['material', $this->s->material->id, '20', 'acc15-po-mat']] as [$type, $id, $qty, $key]) {
        $this->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id, 'branch_id' => $this->s->f->org->a->id, 'location_id' => $this->s->la->id,
            'expected_on' => now()->addWeek()->toDateString(), 'responsible_id' => $this->keeper->id,
            'lines' => [['item_type' => $type, 'item_id' => $id, 'quantity' => $qty, 'unit_cost' => '11.50']], 'idempotency_key' => $key,
        ])->assertRedirect();
        $po = PurchaseOrder::query()->latest('id')->firstOrFail();
        $this->post(route('purchases.confirm', $po), ['lock_version' => $po->lock_version])->assertRedirect(route('purchases.show', $po));
    }

    expect($this->pending->status)->toBe(CheckoutStatus::PendingPayment)
        ->and($this->toRefund->refresh()->attention_reason)->toBe('paid_after_cancellation')
        ->and(storeAvailable($this->s))->toBe('4')
        ->and(PurchaseOrder::query()->where('status', 'ordered')->count())->toBe(2);

    // The downgrade reaches the facility: a newer signed license without `ready_goods`.
    $file = storage_path('framework/testing/acc15-'.bin2hex(random_bytes(4)).'.json');
    file_put_contents($file, LicenseFactory::signed(['plan' => 'basic', 'capabilities' => ['branch_comparisons'], 'issued_at' => now('UTC')->toIso8601ZuluString()]));
    $this->artisan('license:install', ['path' => $file])->assertSuccessful();
    @unlink($file);
    expect(app(CurrentLicense::class)->allows('ready_goods'))->toBeFalse();
});

it('stops every new store sale at once: pages, cart, checkout, links, coupons and new ready orders', function () {
    $orders = Order::query()->count();
    $checkouts = StoreCheckout::query()->count();

    accPFreshBrowser();
    $this->get('/ar/store')->assertNotFound();
    $this->get('/en/store/p/'.$this->variant->ready_product_id)->assertNotFound();
    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 1])->assertNotFound();

    $newcomer = storeBuyer();
    accPCustomer($newcomer);
    $this->post('/ar/store/checkout', ['idempotency_key' => 'acc15-new-000001'])->assertNotFound();

    accPStaff($this->owner);
    // Refused (403 or a refused transition), never created.
    expect($this->postJson('/store-admin/links', ['lines' => [['variant_id' => $this->variant->id, 'quantity' => 1]]])->status())->toBeIn([403, 409, 422])
        ->and($this->postJson('/store-admin/coupons', ['code' => 'AFTER', 'title_ar' => 'بعد', 'kind' => 'percent', 'value' => '5', 'starts_at' => now()->toDateTimeString()])->status())->toBeIn([403, 409, 422])
        ->and($this->postJson('/orders', readyPayload($this->s->f->inA, $this->variant, 1))->status())->toBeIn([403, 409, 422])
        ->and(DB::table('coupons')->count())->toBe(0);

    // The downgrade itself is on the audit trail, without ready goods.
    $installed = DB::table('audit_events')->where('action', 'license.installed')->latest('id')->first();
    expect(json_decode((string) $installed->after, true)['capabilities'])->toBe(['branch_comparisons']);

    expect(Order::query()->count())->toBe($orders)
        ->and(StoreCheckout::query()->count())->toBe($checkouts)
        ->and(StoreLink::query()->count())->toBe(0)
        ->and(FakeMoyasar::$created)->toBe(2);
});

it('settles the payment attempt started before the downgrade: one receipt, the order confirmed, no new sale opened', function () {
    moyasarWebhook(FakeMoyasar::pay($this->pending))->assertOk();

    expect($this->pending->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($this->pending->payment->status)->toBe(PaymentStatus::Confirmed)
        ->and($this->pending->order->refresh()->status)->toBe(ReadyOrderStatus::Confirmed->value)
        ->and(Receipt::query()->where('order_id', $this->pending->order_id)->sole()->amount_minor)->toBe(4500)
        ->and(storeAvailable($this->s))->toBe('4');

    // The history stays readable for the owner (nothing deleted).
    accPStaff($this->owner);
    $this->get("/orders/{$this->pending->order_id}")->assertOk();
});

it('the refund owed before the downgrade is still requested and paid out through the finance screens', function () {
    $item = SettlementItem::query()->where('order_id', $this->toRefund->order_id)->sole();
    expect($item->kind)->toBe('refund')->and($item->target_minor)->toBe(9000);

    accPStaff($this->owner);
    $refundId = (int) $this->postJson("/orders/{$this->toRefund->order_id}/finance/refunds", [
        'payment_id' => $this->toRefund->payment_id, 'amount' => '90.00', 'method' => 'transfer',
        'reason' => 'دفع بعد الإلغاء؛ يرد كاملًا.', 'idempotency_key' => accPKey('refund'),
    ])->assertCreated()->json('refund_id');
    $this->post("/finance/refunds/{$refundId}/confirm", [
        'reference' => 'TRX-ACC15-01', 'proof' => accPPdf('refund.pdf'), 'idempotency_key' => accPKey('refund-paid'),
    ], ['Accept' => 'application/json'])->assertSuccessful();

    $refund = DB::table('refunds')->where('id', $refundId)->first();
    expect($refund->amount_minor)->toBe(9000)
        ->and($refund->status)->toBe('executed')
        ->and(DB::table('refunds')->count())->toBe(1)
        ->and($this->toRefund->order->refresh()->status)->toBe(ReadyOrderStatus::Cancelled->value);
});

it('open purchases are still received (ready variant and custom material); custom work and commission statements stay', function () {
    accPStaff($this->keeper);
    foreach (PurchaseOrder::query()->with('lines')->orderBy('id')->get() as $i => $po) {
        $line = $po->lines->firstOrFail();
        $this->post(route('purchases.receive', $po), [
            'location_id' => $this->s->la->id, 'received_on' => now()->toDateString(), 'reference' => 'DN-15'.$i, 'lock_version' => $po->lock_version,
            'lines' => [['line_id' => $line->id, 'accepted' => $line->item_type === 'material' ? '20' : '10', 'rejected' => '0']],
            'proofs' => [accPPdf('delivery.pdf')],
        ], ['Idempotency-Key' => 'acc15-receive-'.$i])->assertSessionHasNoErrors()->assertRedirect(route('purchases.show', $po));
    }

    expect(PurchaseOrder::query()->where('status', 'received')->count())->toBe(2)
        ->and(stockBalance($this->s->la, 'ready_variant', $this->variant->id)['on_hand'])->toBe('15')
        ->and(stockBalance($this->s->la, 'material', $this->s->material->id)['on_hand'])->toBe('20');

    // Custom products and their materials stay: the catalogue lists the material and a custom order still opens.
    accPStaff($this->owner);
    $this->get('/catalog/materials')->assertOk()->assertSee('Curtain fabric');
    $this->postJson('/orders', customPayload($this->s->f->inA))->assertCreated();
    expect(Order::query()->latest('id')->firstOrFail()->kind)->toBe(OrderKind::Custom);

    // The general commission statement of the store's sales member stays readable.
    $this->getJson("/finance/commissions/members/{$this->s->f->sales->id}")->assertOk();
    stockJournalMatches();
});
