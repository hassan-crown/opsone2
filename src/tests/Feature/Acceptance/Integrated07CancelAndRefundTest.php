<?php

/**
 * «قبول النظام المتكامل» — row «إلغاء ورد»: «حسم العمل والمخزون والمال كل من مصدره، ومنع رد معلق أو منفذ يتجاوز القابل للرد».
 * With the binding integration rows «ردان300 و800 من قابل للرد1000» (SRS-FIN-09) and «دفع إلكتروني يصل بعد قبول الإلغاء»
 * (SRS-INT-08), and the «قرارات تنفيذية مبسطة» rules (impact review from the sources, one settlement follow-up, no
 * automatic refund). Also SRS-COM-14 (management records/accepts a cancellation even with money collected), SRS-FIN-14
 * (cancellation acceptance independent of the money settlement), SRS-INT-01 (decisions re-check the current state:
 * fingerprint) and SRS-INT-05 (sale value and cash refund are separate effects).
 *
 * Every business action goes through HTTP as the right actor (owner = P-18 / P-51, sales = appointments, finance =
 * payments and refunds P-42/P-43, the provider = the verified payment webhook). Fixture only: facility/users/roles
 * (financeFixture), a stock location, a quantity-tracked material and its received stock (stockReceive through the
 * procurement seam), the test payment gateway registration, the start of the customer's online checkout for a
 * made-to-order order (GatewayPayments::begin — no HTTP route exists for it on a custom order), and the provider's
 * «unknown» refund result (RefundProviderResults — a provider seam, no HTTP route).
 */

use App\Actions\StateMachines\M12Refund\RefundProviderResults;
use App\Agreements\CustomerActor;
use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\TaskStatus;
use App\Finance\Gateway\GatewayPayments;
use App\Finance\Gateway\GatewayRegistry;
use App\Finance\Gateway\InvalidGatewaySignature;
use App\Finance\Gateway\PaymentGateway;
use App\Finance\Gateway\VerifiedGatewayEvent;
use App\Finance\MoneyLedger;
use App\Finance\Settlement\RefundLimits;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Finance\Refund;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Settlement\SettlementFollowUp;
use App\Models\Settlement\SettlementItem;
use App\Models\Task;
use App\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';
require_once __DIR__.'/../Inventory/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    $this->year = now()->format('Y');
});

it('settles work, stock and money each from its source on a cancellation with money collected, without an automatic refund', function () {
    $f = $this->f;
    $order = $f->order;
    $year = $this->year;

    // Fixture: a branch-A store with 20 m of a tracked material.
    $location = StockLocation::query()->create(['code' => 'A1', 'name' => ['ar' => 'مستودع أ', 'en' => 'Store A'], 'branch_id' => $f->org->a->id]);
    $material = makeMaterial($f->owner, ['unit' => 'متر', 'track_quantity' => true, 'name' => ['ar' => 'قماش', 'en' => 'Fabric']]);
    stockReceive($location, 'material', $material->id, '20');

    // Agreement 10,000.00, payment 4,000.00, 6.5 m reserved for the order, an appointment proposed — all over HTTP.
    accI07Agree($this, $f, '10000');
    $payment = accI07Pay($this, $f, '4000', 'FT-I07-0001');
    $this->actingAs($f->owner)->postJson("/orders/{$order->id}/materials/reserve", ['location_id' => $location->id, 'material_id' => $material->id, 'quantity' => '6.5'])
        ->assertCreated();
    $this->actingAs($f->sales)->post('/appointments', [
        'order_id' => $order->id, 'kind' => 'measurement', 'starts_at' => '2026-11-03T10:00', 'ends_at' => '2026-11-03T11:00',
        'address' => 'الرياض، حي النرجس', 'member_ids' => [$f->executor->id], 'confirm_mode' => 'propose',
    ])->assertRedirect();
    $appointment = Appointment::query()->where('order_id', $order->id)->sole();
    $openTasks = Task::query()->where('order_id', $order->id)->whereIn('status', TaskStatus::open())->count();
    $tasksBefore = Task::query()->where('order_id', $order->id)->count();
    expect($openTasks)->toBeGreaterThan(0)
        ->and(stockBalance($location, 'material', $material->id))->toBe(['on_hand' => '20', 'reserved' => '6.5', 'available' => '13.5']);

    // The customer's request recorded by management (SRS-COM-14): new work is suspended while pending.
    $this->actingAs($f->owner)->postJson("/orders/{$order->id}/cancellation/requests", [
        ...accI07Request(), 'scope' => 'full', 'lock_version' => $order->refresh()->lock_version, 'idempotency_key' => accI07Key(),
    ])->assertCreated()->assertJsonPath('order.status', 'cancellation_pending')->assertJsonPath('cancellation.number', "CN-{$year}-000001");
    $cancellation = OrderCancellation::query()->sole();

    // The impact review from the sources, then the confirm bound to its fingerprint.
    $preview = $this->actingAs($f->owner)->postJson("/orders/{$order->id}/cancellation/preview", ['scope' => 'full'])->assertOk()->json();
    expect($preview['impact']['settlement']['money']['refund_minor'])->toBe(400_000)
        ->and($preview['impact']['settlement']['money']['collected_minor'])->toBe(400_000)
        ->and($preview['impact']['order']['status_after'])->toBe('cancelled')
        ->and($preview['impact']['stops']['tasks'])->toHaveCount($openTasks)
        ->and(array_column($preview['impact']['settlement']['stock'], 'release_milli'))->toBe([6500]);

    $movementsBefore = StockMovement::query()->count();
    $acceptKey = accI07Key();
    $accept = fn () => $this->actingAs($f->owner)->postJson("/cancellations/{$cancellation->id}/accept", [
        'scope' => 'full', 'hash' => $preview['hash'], 'lock_version' => $preview['lock_version'], 'idempotency_key' => $acceptKey,
    ]);
    $accept()->assertOk()->assertJsonPath('order.status', 'cancelled')->assertJsonPath('cancellation.status', 'accepted')
        ->assertJsonPath('settlement.items.0.kind', 'refund')->assertJsonPath('settlement.items.0.target_minor', 400_000);
    $afterAccept = accI07State();
    $reservation = StockReservation::query()->sole();

    // Work: every open task cancelled, the appointment cancelled, nothing new started.
    expect($order->refresh()->status)->toBe('cancelled')
        ->and(Task::query()->where('order_id', $order->id)->whereIn('status', TaskStatus::open())->count())->toBe(0)
        ->and(Task::query()->where('order_id', $order->id)->where('status', TaskStatus::Cancelled)->count())->toBe($openTasks)
        ->and(Task::query()->where('order_id', $order->id)->count())->toBe($tasksBefore)
        ->and($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled)
        // Stock: the reservation released once; on hand untouched; journal == balance.
        ->and($reservation->status->value)->toBe('released')
        ->and($reservation->released_milli)->toBe(6500)
        ->and(stockBalance($location, 'material', $material->id))->toBe(['on_hand' => '20', 'reserved' => '0', 'available' => '20'])
        ->and(StockMovement::query()->count())->toBe($movementsBefore + 1)
        // Money: collected kept as credit, no automatic refund, one follow-up with one refund item.
        ->and(MoneyLedger::collected($order->id)->minor)->toBe(400_000)
        ->and(MoneyLedger::credit($order->id)->minor)->toBe(400_000)
        ->and(Refund::query()->count())->toBe(0)
        ->and(SettlementFollowUp::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(SettlementItem::query()->where('order_id', $order->id)->pluck('status', 'kind')->sortKeys()->all())->toBe(['refund' => 'open', 'stock_release' => 'done'])
        ->and(DB::table('audit_events')->where('action', 'cancellation.accepted')->count())->toBe(1);
    stockJournalMatches();

    // A replay of the accept is answered without a second effect (no double stock movement).
    $accept()->assertSuccessful();
    expect(accI07State())->toBe($afterAccept);

    // The refund through M-12, within its own limit: over the limit is refused, the exact amount is paid with proof.
    accI07RefundRequest($this, $f, $payment, '4000.01')->assertStatus(409)->assertJsonPath('condition', 'not_refundable');
    $refundId = (int) accI07RefundRequest($this, $f, $payment, '4000')->assertCreated()->json('refund_id');
    $refund = Refund::query()->findOrFail($refundId);
    $this->actingAs($f->finance)->post("/finance/refunds/{$refundId}/confirm", [
        'reference' => 'TRX-I07-1', 'proof' => accI07Pdf('refund'), 'idempotency_key' => accI07Key(), 'lock_version' => $refund->lock_version,
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('number', "RF-{$year}-000001");

    $this->actingAs($f->owner)->getJson("/orders/{$order->id}/cancellation")->assertOk()->assertJsonPath('settlement.status', 'closed');
    expect(SettlementItem::query()->where('order_id', $order->id)->pluck('status', 'kind')->sortKeys()->all())->toBe(['refund' => 'done', 'stock_release' => 'done']);
    expect(MoneyLedger::collected($order->id)->minor)->toBe(0)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(SettlementFollowUp::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($order->refresh()->status)->toBe('cancelled');
    setLedgerBalanced();
});

it('binding «ردان300 و800 من قابل للرد1000»: a pending 300 reserves its limit, 800 is refused until the 300 finally fails', function () {
    $f = $this->f;
    $order = $f->order;
    accI07Agree($this, $f, '1000');
    $payment = accI07Pay($this, $f, '1000', 'FT-I07-0002');

    // Cancelled with the money collected (SRS-FIN-14): recorded and accepted at once after the review.
    $preview = $this->actingAs($f->owner)->postJson("/orders/{$order->id}/cancellation/preview", ['scope' => 'full'])->assertOk()->json();
    $this->actingAs($f->owner)->postJson("/orders/{$order->id}/cancellation", [
        ...accI07Request(), 'scope' => 'full', 'hash' => $preview['hash'], 'lock_version' => $preview['lock_version'], 'idempotency_key' => accI07Key(),
    ])->assertCreated()->assertJsonPath('order.status', 'cancelled');
    expect(Refund::query()->count())->toBe(0)
        ->and(RefundLimits::refundable($payment, $order->id)->minor)->toBe(100_000);

    // 300 requested: reserved, nothing leaves the ledger.
    $firstId = (int) accI07RefundRequest($this, $f, $payment, '300')->assertCreated()->json('refund_id');
    $first = Refund::query()->findOrFail($firstId);
    expect(MoneyLedger::collected($order->id)->minor)->toBe(100_000)
        ->and(RefundLimits::refundable($payment, $order->id)->minor)->toBe(70_000);
    accI07RefundRequest($this, $f, $payment, '800')->assertStatus(409)->assertJsonPath('condition', 'not_refundable');

    // Sent to the bank: still pending, still reserved.
    $this->actingAs($f->finance)->postJson("/finance/refunds/{$firstId}/start", ['reference' => 'TRX-300', 'idempotency_key' => accI07Key(), 'lock_version' => $first->lock_version])->assertOk();
    accI07RefundRequest($this, $f, $payment, '800')->assertStatus(409)->assertJsonPath('condition', 'not_refundable');

    // The provider cannot tell (unknown): the limit is not released.
    app(RefundProviderResults::class)->unresolved($first->refresh(), 'TRX-300');
    expect($first->refresh()->status)->toBe(RefundStatus::Unresolved);
    accI07RefundRequest($this, $f, $payment, '800')->assertStatus(409)->assertJsonPath('condition', 'not_refundable');

    // A proven final failure releases the limit; the 800 then fits.
    $this->actingAs($f->finance)->postJson("/finance/refunds/{$firstId}/fail", ['reason' => 'رفض البنك التحويل.', 'idempotency_key' => accI07Key(), 'lock_version' => $first->lock_version])->assertOk();
    expect($first->refresh()->status)->toBe(RefundStatus::Failed)
        ->and(MoneyLedger::collected($order->id)->minor)->toBe(100_000)
        ->and(RefundLimits::refundable($payment, $order->id)->minor)->toBe(100_000);
    $secondId = (int) accI07RefundRequest($this, $f, $payment, '800')->assertCreated()->json('refund_id');
    expect(RefundLimits::refundable($payment, $order->id)->minor)->toBe(20_000);

    // Retrying the failed 300 re-checks the limit next to the pending 800: refused.
    $this->actingAs($f->finance)->postJson("/finance/refunds/{$firstId}/retry", ['idempotency_key' => accI07Key(), 'lock_version' => $first->refresh()->lock_version])
        ->assertStatus(409)->assertJsonPath('condition', 'not_refundable');

    $second = Refund::query()->findOrFail($secondId);
    $this->actingAs($f->finance)->post("/finance/refunds/{$secondId}/confirm", [
        'reference' => 'TRX-800', 'proof' => accI07Pdf('r800'), 'idempotency_key' => accI07Key(), 'lock_version' => $second->lock_version,
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('number', 'RF-'.$this->year.'-000001');

    expect(MoneyLedger::collected($order->id)->minor)->toBe(20_000)
        ->and(RefundLimits::refundable($payment, $order->id)->minor)->toBe(20_000)
        ->and(Refund::query()->count())->toBe(2)
        ->and((int) Refund::query()->where('status', RefundStatus::Executed)->sum('amount_minor'))->toBe(80_000)
        ->and(DB::table('audit_events')->where('action', 'refund.requested')->count())->toBe(2)
        ->and(DB::table('audit_events')->where('action', 'refund.failed')->count())->toBe(1)
        ->and(SettlementFollowUp::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($order->refresh()->status)->toBe('cancelled');
    // Above what is left refundable is refused even after execution.
    accI07RefundRequest($this, $f, $payment, '200.01')->assertStatus(409)->assertJsonPath('condition', 'not_refundable');
    setLedgerBalanced();
});

it('binding «دفع إلكتروني يصل بعد قبول الإلغاء»: receipt and settlement only; the order stays cancelled and no work starts', function () {
    $f = $this->f;
    $order = $f->order;
    app(GatewayRegistry::class)->add(accI07Gateway());
    accI07Agree($this, $f, '1000');
    accI07Pay($this, $f, '400', 'FT-I07-0003');

    // Fixture: the customer started an online checkout of 600.00 before the cancellation.
    $customer = new CustomerActor($order->customer_id, $order->customer->mobile);
    ['payment' => $online] = app(GatewayPayments::class)->begin($customer, $order->refresh(), Money::of(60_000), 'accpay', 'https://shop.test/back');
    expect($online->status)->toBe(PaymentStatus::PendingConfirmation);

    $preview = $this->actingAs($f->owner)->postJson("/orders/{$order->id}/cancellation/preview", ['scope' => 'full'])->assertOk()->json();
    $this->actingAs($f->owner)->postJson("/orders/{$order->id}/cancellation", [
        ...accI07Request(), 'scope' => 'full', 'hash' => $preview['hash'], 'lock_version' => $preview['lock_version'], 'idempotency_key' => accI07Key(),
    ])->assertCreated()->assertJsonPath('order.status', 'cancelled');
    $tasks = Task::query()->where('order_id', $order->id)->get()->map(fn (Task $t) => [$t->id, $t->status->value])->all();
    $actions = DB::table('order_actions')->where('order_id', $order->id)->get()->map(fn ($a) => [$a->id, $a->status])->all();
    $movements = StockMovement::query()->count();

    // The provider's verified notification arrives after the acceptance; a forged one is refused first.
    $event = ['ref' => accI07MerchantRef($online), 'tx' => 'TX-LATE', 'outcome' => 'succeeded', 'amount' => 60_000, 'currency' => 'SAR'];
    accI07Webhook($event, 'forged')->assertStatus(400);
    accI07Webhook($event)->assertOk();
    accI07Webhook($event)->assertOk();

    expect($online->refresh()->status)->toBe(PaymentStatus::Confirmed)
        ->and(Receipt::query()->where('payment_id', $online->id)->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(2)
        ->and(MoneyLedger::collected($order->id)->minor)->toBe(100_000)
        // The order stays cancelled; no work, no stock movement, no automatic refund.
        ->and($order->refresh()->status)->toBe('cancelled')
        ->and(Task::query()->where('order_id', $order->id)->get()->map(fn (Task $t) => [$t->id, $t->status->value])->all())->toBe($tasks)
        ->and(DB::table('order_actions')->where('order_id', $order->id)->get()->map(fn ($a) => [$a->id, $a->status])->all())->toBe($actions)
        ->and(StockMovement::query()->count())->toBe($movements)
        ->and(Refund::query()->count())->toBe(0)
        ->and(Agreement::query()->where('order_id', $order->id)->where('status', 'active')->count())->toBeLessThanOrEqual(1);

    // Settlement only: still one follow-up for the order, with the late money as its own refund item (INT-08).
    $this->actingAs($f->owner)->getJson("/orders/{$order->id}/cancellation")->assertOk()
        ->assertJsonPath('settlement.status', 'open')
        ->assertJsonPath('settlement.items.0.kind', 'refund');
    $this->actingAs($f->finance)->getJson("/orders/{$order->id}/finance")->assertOk()
        ->assertJsonPath('finance.summary.collected_minor', 100_000)
        ->assertJsonPath('finance.summary.credit_minor', 100_000);
    expect(SettlementFollowUp::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(SettlementItem::query()->where('order_id', $order->id)->where('kind', 'refund')->count())->toBe(2)
        ->and(SettlementItem::query()->where('order_id', $order->id)->where('ref_type', 'payment')->where('ref_id', $online->id)->value('target_minor'))->toBe(60_000);
});

it('binding «دفع إلكتروني يصل بعد قبول الإلغاء»: the settlement follow-up follows the late money and does not close while it is unsettled', function () {
    $f = $this->f;
    $order = $f->order;
    app(GatewayRegistry::class)->add(accI07Gateway());
    accI07Agree($this, $f, '1000');
    $first = accI07Pay($this, $f, '400', 'FT-I07-0004');
    $customer = new CustomerActor($order->customer_id, $order->customer->mobile);
    ['payment' => $online] = app(GatewayPayments::class)->begin($customer, $order->refresh(), Money::of(60_000), 'accpay', 'https://shop.test/back');

    $preview = $this->actingAs($f->owner)->postJson("/orders/{$order->id}/cancellation/preview", ['scope' => 'full'])->assertOk()->json();
    $this->actingAs($f->owner)->postJson("/orders/{$order->id}/cancellation", [
        ...accI07Request(), 'scope' => 'full', 'hash' => $preview['hash'], 'lock_version' => $preview['lock_version'], 'idempotency_key' => accI07Key(),
    ])->assertCreated();
    accI07Webhook(['ref' => accI07MerchantRef($online), 'tx' => 'TX-LATE-2', 'outcome' => 'succeeded', 'amount' => 60_000, 'currency' => 'SAR'])->assertOk();
    expect(MoneyLedger::collected($order->id)->minor)->toBe(100_000);

    // The late money joins the one follow-up as its own refund item: 400.00 from the decision + 600.00 paid late.
    expect(SettlementFollowUp::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(SettlementItem::query()->where('order_id', $order->id)->where('kind', 'refund')->orderBy('id')->pluck('target_minor')->all())->toBe([40_000, 60_000]);

    // Refunding only the first 400.00 must leave the follow-up open while 600.00 of the customer's money is unsettled.
    $refundId = (int) accI07RefundRequest($this, $f, $first, '400')->assertCreated()->json('refund_id');
    $this->actingAs($f->finance)->post("/finance/refunds/{$refundId}/confirm", [
        'reference' => 'TRX-I07-400', 'proof' => accI07Pdf('r400'), 'idempotency_key' => accI07Key(), 'lock_version' => Refund::query()->findOrFail($refundId)->lock_version,
    ], ['Accept' => 'application/json'])->assertOk();
    $this->actingAs($f->owner)->getJson("/orders/{$order->id}/cancellation")->assertOk()
        ->assertJsonPath('settlement.status', 'open');
    expect(MoneyLedger::collected($order->id)->minor)->toBe(60_000);
});

function accI07Key(): string
{
    return (string) Str::uuid();
}

function accI07Pdf(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent($body.'.pdf', "%PDF-1.4\n".$body);
}

/**
 * @return array<string, string>
 */
function accI07Request(): array
{
    return ['source' => 'external', 'channel' => 'phone', 'requested_on' => now()->toDateString(), 'reason' => 'العميل ألغى المشروع لظروف السفر.'];
}

/**
 * Quote → price → send by sales, external approval with evidence by the owner, over HTTP (one VAT-inclusive line).
 */
function accI07Agree(mixed $test, object $f, string $total): Agreement
{
    $order = $f->order;
    $id = (int) $test->actingAs($f->sales)->postJson("/orders/{$order->id}/agreement/quotes", ['idempotency_key' => accI07Key()])->assertCreated()->json('document_id');
    $quote = AgreementDocument::query()->findOrFail($id);
    $test->actingAs($f->sales)->putJson("/agreement/documents/{$id}", [...agrQuoteData(lines: [finLine('مطبخ', $total)]), 'idempotency_key' => accI07Key(), 'lock_version' => $quote->lock_version])->assertOk();
    $test->actingAs($f->sales)->postJson("/agreement/documents/{$id}/send", ['idempotency_key' => accI07Key(), 'lock_version' => $quote->refresh()->lock_version])->assertOk();
    $quote->refresh();
    $test->actingAs($f->owner)->post("/agreement/documents/{$id}/external-approval", [
        'idempotency_key' => accI07Key(), 'lock_version' => $quote->lock_version, 'channel' => 'message',
        'decided_on' => now()->toDateString(), 'content_hash' => (string) $quote->content_hash, 'evidence' => accI07Pdf('agree'),
    ], ['Accept' => 'application/json'])->assertOk();

    return Agreement::query()->where('order_id', $order->id)->where('status', 'active')->sole();
}

function accI07Pay(mixed $test, object $f, string $amount, string $reference): Payment
{
    $order = $f->order;
    $test->actingAs($f->finance)->post("/orders/{$order->id}/finance/payments", [
        'amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => $reference,
        'lock_version' => finVersion($order), 'idempotency_key' => accI07Key(), 'evidence' => accI07Pdf($reference),
    ], ['Accept' => 'application/json'])->assertCreated();

    return Payment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
}

function accI07RefundRequest(mixed $test, object $f, Payment $payment, string $amount): mixed
{
    return $test->actingAs($f->finance)->postJson("/orders/{$f->order->id}/finance/refunds", [
        'payment_id' => $payment->id, 'amount' => $amount, 'method' => 'transfer', 'reason' => 'رد المقبوض بعد إلغاء الطلب.',
        'idempotency_key' => accI07Key(), 'lock_version' => finVersion($f->order),
    ]);
}

/**
 * Rows a replayed cancellation must not touch.
 *
 * @return array<string, mixed>
 */
function accI07State(): array
{
    return [
        'orders' => DB::table('orders')->get()->map(fn ($o) => [$o->id, $o->status, $o->lock_version, $o->total_minor])->all(),
        'cancellations' => DB::table('order_cancellations')->get()->map(fn ($c) => [$c->id, $c->status, $c->lock_version])->all(),
        'tasks' => DB::table('tasks')->get()->map(fn ($t) => [$t->id, $t->status])->all(),
        'appointments' => DB::table('appointments')->get()->map(fn ($a) => [$a->id, $a->status])->all(),
        'reservations' => DB::table('stock_reservations')->get()->map(fn ($r) => [$r->id, $r->status, $r->released_milli, $r->issued_milli])->all(),
        'movements' => DB::table('stock_movements')->count(),
        'agreements' => DB::table('agreements')->get()->map(fn ($a) => [$a->id, $a->status, $a->total_minor])->all(),
        'dues' => DB::table('order_dues')->get()->map(fn ($d) => [$d->id, $d->amount_minor, $d->removed_at])->all(),
        'journals' => DB::table('money_journals')->count(),
        'refunds' => DB::table('refunds')->count(),
        'follow_ups' => SettlementFollowUp::query()->count(),
        'items' => DB::table('settlement_items')->get()->map(fn ($i) => [$i->id, $i->kind, $i->status])->all(),
        'audit' => DB::table('audit_events')->count(),
        'transitions' => DB::table('state_transitions')->count(),
    ];
}

/**
 * The provider's merchant reference of a pending online payment (what the provider echoes in its notification).
 */
function accI07MerchantRef(Payment $payment): string
{
    return (string) $payment->refresh()->reference;
}

/**
 * Test payment provider: a notification is trusted only with the right HMAC signature.
 */
function accI07Gateway(): PaymentGateway
{
    return new class implements PaymentGateway
    {
        public function key(): string
        {
            return 'accpay';
        }

        public function checkoutUrl(Payment $payment, string $merchantReference, string $returnUrl): string
        {
            return 'https://pay.test/checkout/'.$merchantReference;
        }

        public function verify(Request $request): VerifiedGatewayEvent
        {
            $body = (string) $request->getContent();
            if (! hash_equals(hash_hmac('sha256', $body, 'acc-secret'), (string) $request->header('X-Signature'))) {
                throw new InvalidGatewaySignature('bad signature');
            }
            $data = json_decode($body, true);

            return new VerifiedGatewayEvent($data['ref'], $data['tx'], $data['outcome'], $data['amount'], $data['currency']);
        }

        public function fetch(Payment $payment): ?VerifiedGatewayEvent
        {
            return null;
        }
    };
}

/**
 * @param  array<string, mixed>  $data
 */
function accI07Webhook(array $data, ?string $signature = null): mixed
{
    $body = (string) json_encode($data);

    return test()->call('POST', '/webhooks/payments/accpay', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'acc-secret'),
    ], $body);
}
