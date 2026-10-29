<?php

/**
 * «قبول النظام المتكامل» — row «توريد وتسليم جزئيان»: استلام وصرف بالكميات الفعلية ومتبقٍ معلوم وملاحظة إصلاح مرتبطة
 * دون تكرار الحركات. Plus the binding test «تسليم2 من5 ثم إلغاء الباقي» (SRS-FUL-09, SRS-INT-01).
 *
 * Requirements: SRS-FUL-05 (supplier purchase order), SRS-FUL-06 (actual partial receipt: accepted / rejected /
 * remaining), SRS-FUL-07 (remainder kept), SRS-FUL-09 (delivery of actual quantities with receiver, date and proof),
 * SRS-FUL-10 (repair note with linked tasks), SRS-FUL-12 / SRS-INT-01 (cancellation releases only what was not
 * issued), NFR-04 (quantities in thousandths), NFR-05 (no duplicated effect on a repeated or racing write).
 *
 * Every business step goes through HTTP as the acting member: purchase create → confirm → receive (twice), ready order
 * create → confirm (reservation) → delivery draft → record with proof (twice), cancellation review → apply. Fixtures
 * (organization, catalog, supplier, stock location) come from the existing test helpers.
 */

use App\Models\Delivery\DeliveryNote;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Procurement\PurchaseReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';
require_once __DIR__.'/../Procurement/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->s = stockFixture();
    $this->supplier = procSupplier();
    $this->keeper = $this->s->keeper;
    $this->year = now()->year;
});

it('receives a purchase in actual parts: accepted, rejected and remaining in thousandths, one receipt movement per accepted line, no duplicates', function () {
    $s = $this->s;
    $this->actingAs($this->keeper);

    $po = accI06CreatePurchase($this, [
        ['item_type' => 'ready_variant', 'item_id' => $s->f->variant->id, 'quantity' => '10', 'unit_cost' => '11.50'],
        ['item_type' => 'material', 'item_id' => $s->material->id, 'quantity' => '5', 'unit_cost' => '20'],
    ], 'acc06-po-create-1');
    expect($po->number)->toBe("PO-{$this->year}-000001")->and($po->status->value)->toBe('ordered');
    [$variantLine, $materialLine] = $po->lines()->orderBy('line_no')->get()->all();

    // First arrival: 5 pieces of the variant, 4 accepted and 1 broken; nothing of the material.
    $first = [
        'location_id' => $s->la->id, 'received_on' => now()->toDateString(), 'reference' => 'SUP-DN-1', 'lock_version' => $po->refresh()->lock_version,
        'lines' => [['line_id' => $variantLine->id, 'accepted' => '4', 'rejected' => '1', 'reject_reason' => 'مكسور']],
        'proofs' => [procPdf()],
    ];
    $this->post(route('purchases.receive', $po), $first, ['Idempotency-Key' => 'acc06-receive-1'])->assertRedirect(route('purchases.show', $po));
    // A double submit with the same key replays the first result: one receipt, one movement.
    $this->post(route('purchases.receive', $po), $first, ['Idempotency-Key' => 'acc06-receive-1'])->assertRedirect(route('purchases.show', $po));

    expect(PurchaseReceipt::query()->count())->toBe(1)
        ->and($po->refresh()->status->value)->toBe('partially_received')
        ->and(accI06Line($variantLine))->toBe(['received' => 5_000, 'accepted' => 4_000, 'rejected' => 1_000, 'remaining' => 6_000])
        ->and(accI06Line($materialLine))->toBe(['received' => 0, 'accepted' => 0, 'rejected' => 0, 'remaining' => 5_000])
        ->and(accI06Receipts())->toBe([4_000])
        // Rejected goods never become stock.
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '4', 'reserved' => '0', 'available' => '4']);

    // Second arrival completes both lines: one receipt, one movement per accepted line.
    $second = [
        'location_id' => $s->la->id, 'received_on' => now()->toDateString(), 'reference' => 'SUP-DN-2', 'lock_version' => $po->refresh()->lock_version,
        'lines' => [['line_id' => $variantLine->id, 'accepted' => '6'], ['line_id' => $materialLine->id, 'accepted' => '5']],
        'proofs' => [procPdf('second.pdf')],
    ];
    $this->post(route('purchases.receive', $po), $second, ['Idempotency-Key' => 'acc06-receive-2'])->assertRedirect(route('purchases.show', $po));
    $this->post(route('purchases.receive', $po), $second, ['Idempotency-Key' => 'acc06-receive-2'])->assertRedirect(route('purchases.show', $po));

    expect(PurchaseReceipt::query()->count())->toBe(2)
        ->and($po->refresh()->status->value)->toBe('received')
        ->and(accI06Line($variantLine))->toBe(['received' => 11_000, 'accepted' => 10_000, 'rejected' => 1_000, 'remaining' => 0])
        ->and(accI06Line($materialLine))->toBe(['received' => 5_000, 'accepted' => 5_000, 'rejected' => 0, 'remaining' => 0])
        ->and(accI06Receipts())->toBe([4_000, 6_000, 5_000])
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id)['on_hand'])->toBe('10')
        ->and(stockBalance($s->la, 'material', $s->material->id)['on_hand'])->toBe('5');

    // The same delivery submitted again under a new key is refused: the order owes nothing anymore (no over-receipt
    // without an explicit adjust), and nothing moves.
    $this->post(route('purchases.receive', $po), [...$second, 'lock_version' => $po->refresh()->lock_version], ['Idempotency-Key' => 'acc06-receive-3'])
        ->assertSessionHasErrors();
    expect(PurchaseReceipt::query()->count())->toBe(2)->and(accI06Receipts())->toBe([4_000, 6_000, 5_000])
        ->and(DB::table('audit_events')->where('action', 'purchase.received')->count())->toBe(2)
        ->and(DB::table('audit_events')->where('action', 'purchase.created')->count())->toBe(1);
    stockJournalMatches();
});

it('shows the partially received purchase with its accepted, rejected and remaining quantities and the receipt proof', function () {
    $s = $this->s;
    $this->actingAs($this->keeper);
    $po = accI06CreatePurchase($this, [['item_type' => 'ready_variant', 'item_id' => $s->f->variant->id, 'quantity' => '10', 'unit_cost' => '11.50']], 'acc06-po-create-show');
    $line = $po->lines()->firstOrFail();
    $this->post(route('purchases.receive', $po), [
        'location_id' => $s->la->id, 'received_on' => now()->toDateString(), 'lock_version' => $po->refresh()->lock_version,
        'lines' => [['line_id' => $line->id, 'accepted' => '4', 'rejected' => '1', 'reject_reason' => 'مكسور']], 'proofs' => [procPdf()],
    ], ['Idempotency-Key' => 'acc06-receive-show'])->assertRedirect(route('purchases.show', $po));

    $this->get(route('purchases.show', $po))->assertOk()->assertInertia(fn (Assert $p) => $p->component('purchases/show')
        ->where('purchase.status', 'partially_received')
        ->where('purchase.receipts.0.lines.0.accepted_milli', 4_000)->where('purchase.receipts.0.lines.0.rejected_milli', 1_000)
        ->has('purchase.receipts.0.files', 1));
});

it('serves a ready order from the received stock in partial deliveries with actual quantities, proof and DN numbers, never issuing twice', function () {
    $s = $this->s;
    $this->actingAs($this->keeper);
    $po = accI06CreatePurchase($this, [['item_type' => 'ready_variant', 'item_id' => $s->f->variant->id, 'quantity' => '10', 'unit_cost' => '11.50']], 'acc06-po-create-2');
    $line = $po->lines()->firstOrFail();
    $this->post(route('purchases.receive', $po), [
        'location_id' => $s->la->id, 'received_on' => now()->toDateString(), 'lock_version' => $po->refresh()->lock_version,
        'lines' => [['line_id' => $line->id, 'accepted' => '6', 'rejected' => '0']], 'proofs' => [procPdf()],
    ], ['Idempotency-Key' => 'acc06-receive-ready'])->assertRedirect();
    expect(accI06Line($line))->toMatchArray(['accepted' => 6_000, 'remaining' => 4_000]);

    // Sales confirms a ready order of 5 on the received stock: the reservation holds 5.
    $order = accI06ConfirmedReadyOrder($this, 5);
    $orderLine = deliveryLineId($order);
    $reservation = StockReservation::query()->sole();
    expect($reservation->quantity_milli)->toBe(5_000)->and($reservation->remainingMilli())->toBe(5_000)
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '6', 'reserved' => '5', 'available' => '1']);

    // First delivery: 2 actually handed over.
    $this->actingAs($this->keeper);
    $first = accI06Deliver($this, $order, [$orderLine => '2'], 'acc06-dn-1');
    $first->assertOk()->assertJsonPath('note.status', 'confirmed')->assertJsonPath('note.number', "DN-{$this->year}-000001")
        ->assertJsonPath('note.receiver_name', 'خالد العتيبي')->assertJsonCount(1, 'note.proofs');
    $noteId = (int) $first->json('note.id');

    // The double submit of the same record (same key) replays it; a second record under another key is a state error.
    $this->post("/deliveries/{$noteId}/confirm", accI06ConfirmBody(1, 'acc06-dn-1-confirm'), ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('note.id', $noteId)->assertJsonPath('note.number', "DN-{$this->year}-000001");
    $this->post("/deliveries/{$noteId}/confirm", accI06ConfirmBody(1, 'acc06-dn-1-again'), ['Accept' => 'application/json'])->assertStatus(409);

    expect(StockMovement::query()->where('kind', 'issue_reserved')->count())->toBe(1)
        ->and($order->refresh()->status)->toBe('partially_delivered')
        ->and($reservation->refresh()->issued_milli)->toBe(2_000)
        ->and($reservation->remainingMilli())->toBe(3_000)
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '4', 'reserved' => '3', 'available' => '1']);

    // The remainder is known on the delivery screen; more than it is refused.
    $this->get("/orders/{$order->id}/deliveries/create")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('deliveries/form')->where('lines.0.ordered', '5')->where('lines.0.delivered', '2')->where('lines.0.deliverable', '3'));
    $this->postJson("/orders/{$order->id}/deliveries", ['idempotency_key' => 'acc06-dn-over', 'lines' => [['order_line_id' => $orderLine, 'quantity' => '4']]])
        ->assertUnprocessable();

    // Second delivery: the remaining 3.
    accI06Deliver($this, $order, [$orderLine => '3'], 'acc06-dn-2')->assertOk()->assertJsonPath('note.number', "DN-{$this->year}-000002");

    $issues = StockMovement::query()->where('kind', 'issue_reserved')->orderBy('id')->get();
    expect($order->refresh()->status)->toBe('delivered')
        ->and($issues->pluck('quantity_milli')->all())->toBe([2_000, 3_000])
        ->and($issues->pluck('source_type')->unique()->all())->toBe(['delivery_note'])
        ->and($reservation->refresh()->status->value)->toBe('issued')
        ->and($reservation->remainingMilli())->toBe(0)
        ->and(DeliveryNote::query()->where('status', 'confirmed')->count())->toBe(2)
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '1', 'reserved' => '0', 'available' => '1'])
        ->and(DB::table('audit_events')->where('action', 'delivery.confirmed')->count())->toBe(2)
        // The purchase still owes 4: the supplier remainder is independent of the customer's delivery.
        ->and(accI06Line($line)['remaining'])->toBe(4_000);
    $this->postJson("/orders/{$order->id}/deliveries", ['idempotency_key' => 'acc06-dn-after', 'lines' => [['order_line_id' => $orderLine, 'quantity' => '1']]])
        ->assertStatus(409);
    stockJournalMatches();
});

it('links a repair note with a follow-up task to a recorded delivery (SRS-FUL-10)', function () {
    $s = $this->s;
    stockReceive($s->la, 'ready_variant', $s->f->variant->id, '5');
    $order = accI06ConfirmedReadyOrder($this, 2);
    $this->actingAs($this->keeper);
    $noteId = (int) accI06Deliver($this, $order, [deliveryLineId($order) => '2'], 'acc06-dn-repair')->assertOk()->json('note.id');

    // A blocking remark on the recorded delivery opens a linked correction task (M-14-T04); no new sale, no stock.
    expect(Route::has('deliveries.remarks.store'))->toBeTrue();
    $this->postJson(route('deliveries.remarks.store', $noteId), ['idempotency_key' => 'acc06-remark-1', 'text' => 'المقبض يحتاج إصلاحًا', 'blocking' => true])
        ->assertCreated();
    expect(DB::table('tasks')->where('source_type', 'delivery_note')->where('source_id', $noteId)->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1)
        ->and(StockMovement::query()->where('kind', 'issue_reserved')->count())->toBe(1);
})->skip('Acceptance gap: repair/correction note linked to a delivery (M-14-T03/T04, SRS-FUL-10) is deferred in ADR-0018 — no route or model');

it('delivers 2 of 5 then cancels the rest: the delivery of 2 stays, only the 3 unissued are released, no return of 5 («تسليم2 من5 ثم إلغاء الباقي»)', function () {
    $s = $this->s;
    stockReceive($s->la, 'ready_variant', $s->f->variant->id, '10');
    $order = accI06ConfirmedReadyOrder($this, 5);
    $orderLine = deliveryLineId($order);

    $this->actingAs($this->keeper);
    $noteId = (int) accI06Deliver($this, $order, [$orderLine => '2'], 'acc06-dn-cut')->assertOk()->json('note.id');

    // The owner reviews the cancellation of the rest, then applies it with the reviewed fingerprint.
    $owner = $s->f->org->owner;
    $this->actingAs($owner);
    $preview = $this->postJson("/orders/{$order->id}/cancellation/preview", ['scope' => 'full'])->assertOk()
        ->assertJsonPath('impact.order.status_after', 'delivered')
        ->assertJsonPath('impact.stays.delivered.0.delivered_milli', 2_000)
        ->assertJsonPath('impact.settlement.stock.0.release_milli', 3_000)->json();
    $body = [
        'source' => 'external', 'channel' => 'phone', 'requested_on' => now()->toDateString(), 'reason' => 'العميل لا يريد الباقي.',
        'scope' => 'full', 'hash' => $preview['hash'], 'lock_version' => $order->refresh()->lock_version, 'idempotency_key' => 'acc06-cancel-rest',
    ];
    $this->postJson("/orders/{$order->id}/cancellation", $body)->assertSuccessful()
        ->assertJsonPath('cancellation.number', "CN-{$this->year}-000001")->assertJsonPath('order.status', 'delivered');
    // A double submit replays the decision without a second release.
    $this->postJson("/orders/{$order->id}/cancellation", $body)->assertSuccessful();

    $reservation = StockReservation::query()->sole();
    expect($order->refresh()->status)->toBe('delivered')
        ->and(DeliveryNote::query()->findOrFail($noteId)->status->value)->toBe('confirmed')
        ->and($reservation->issued_milli)->toBe(2_000)
        ->and($reservation->released_milli)->toBe(3_000)
        ->and($reservation->status->value)->toBe('settled_mixed')
        ->and(StockMovement::query()->where('kind', 'release')->pluck('quantity_milli')->all())->toBe([3_000])
        ->and(StockMovement::query()->where('kind', 'issue_reserved')->pluck('quantity_milli')->all())->toBe([2_000])
        // No return of 5: nothing comes back to stock and no return record exists.
        ->and(StockMovement::query()->whereIn('kind', ['return_in', 'return_damaged', 'receipt'])->where('source_type', '!=', 'purchase_receipt')->count())->toBe(0)
        ->and(DB::table('order_returns')->count())->toBe(0)
        ->and(DB::table('order_cancellations')->count())->toBe(1)
        ->and(stockBalance($s->la, 'ready_variant', $s->f->variant->id))->toBe(['on_hand' => '8', 'reserved' => '0', 'available' => '8'])
        ->and(DB::table('audit_events')->where('action', 'cancellation.accepted')->count())->toBe(1);
    stockJournalMatches();
});

/**
 * Creates (draft) and confirms a purchase order through the screens as the acting member.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function accI06CreatePurchase(object $test, array $lines, string $key): PurchaseOrder
{
    $s = $test->s;
    $test->post(route('purchases.store'), [
        'supplier_id' => $test->supplier->id, 'branch_id' => $s->f->org->a->id, 'location_id' => $s->la->id,
        'expected_on' => now()->addWeek()->toDateString(), 'responsible_id' => $test->keeper->id, 'lines' => $lines, 'idempotency_key' => $key,
    ])->assertRedirect();
    // The same key never makes a second purchase order.
    $test->post(route('purchases.store'), [
        'supplier_id' => $test->supplier->id, 'branch_id' => $s->f->org->a->id, 'location_id' => $s->la->id,
        'expected_on' => now()->addWeek()->toDateString(), 'responsible_id' => $test->keeper->id, 'lines' => $lines, 'idempotency_key' => $key,
    ])->assertRedirect();
    $po = PurchaseOrder::query()->latest('id')->firstOrFail();
    expect(PurchaseOrder::query()->where('status', 'draft')->count())->toBe(1);
    $test->post(route('purchases.confirm', $po), ['lock_version' => $po->lock_version])->assertRedirect(route('purchases.show', $po));

    return $po->refresh();
}

/**
 * @return array{received: int, accepted: int, rejected: int, remaining: int}
 */
function accI06Line(PurchaseOrderLine $line): array
{
    $line->refresh();

    return ['received' => $line->received_milli, 'accepted' => $line->accepted_milli, 'rejected' => $line->rejected_milli, 'remaining' => $line->remainingMilli()];
}

/**
 * Quantities of the purchase receipt movements, in journal order.
 *
 * @return list<int>
 */
function accI06Receipts(): array
{
    return StockMovement::query()->where('kind', 'receipt')->where('source_type', 'purchase_receipt')->orderBy('id')->pluck('quantity_milli')->map(fn ($q) => (int) $q)->all();
}

/**
 * The owner creates a ready order through the orders API and the sales member of branch A confirms it on store A1.
 */
function accI06ConfirmedReadyOrder(object $test, int $quantity): Order
{
    $s = $test->s;
    $order = readyOrder($s, $quantity);
    /** @var User $sales */
    $sales = $s->f->sales;
    $test->actingAs($sales)->postJson("/orders/{$order->id}/confirm", ['location_id' => $s->la->id, 'lock_version' => $order->refresh()->lock_version])
        ->assertOk()->assertJsonPath('order.status', 'confirmed');

    return $order->refresh();
}

/**
 * @return array<string, mixed>
 */
function accI06ConfirmBody(int $lockVersion, string $key): array
{
    return [
        'idempotency_key' => $key, 'lock_version' => $lockVersion, 'delivered_on' => now()->toDateString(),
        'receiver_name' => 'خالد العتيبي', 'note' => 'سُلّم عند الباب',
        'proofs' => [UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n%receipt {$key}\n%%EOF")],
    ];
}

/**
 * Drafts a delivery note of the given actual quantities and records it with a receiver and a proof file.
 *
 * @param  array<int, string>  $quantities  order line id → quantity text
 */
function accI06Deliver(object $test, Order $order, array $quantities, string $key): TestResponse
{
    $lines = [];
    foreach ($quantities as $lineId => $quantity) {
        $lines[] = ['order_line_id' => $lineId, 'quantity' => $quantity];
    }
    $draft = $test->postJson("/orders/{$order->id}/deliveries", ['idempotency_key' => $key.'-draft', 'lines' => $lines])
        ->assertCreated()->assertJsonPath('note.status', 'draft')->json('note');

    return $test->post("/deliveries/{$draft['id']}/confirm", accI06ConfirmBody((int) $draft['lock_version'], $key.'-confirm'), ['Accept' => 'application/json']);
}
