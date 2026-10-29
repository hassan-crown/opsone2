<?php

/**
 * «قبول النظام المتكامل» — row «مخصص من البداية»:
 * «فتح بلا سعر، تنفيذ المطلوب فقط، واعتماد العرض على الطلب نفسه، ثم تحصيل وتنفيذ وتسليم.»
 *
 * Traceability: SRS-COM-08 (open a custom order without price/quote/measurement), SRS-COM-09 / SRS-COM-12 (one custom
 * flow, the order as the axis of agreement, execution, collection and delivery), SRS-WF-05 / SRS-WF-06 / SRS-WF-08 /
 * SRS-WF-12 (required vs optional actions, one active stage, tasks with only what is required, stage completion),
 * SRS-AGR-01 / SRS-AGR-04 (quote inside the order, sent content approved by the customer), SRS-COM-11 (agreement fixed
 * at approval), SRS-FIN-02 / SRS-FIN-04 / SRS-FIN-06 (schedule, payment recorded by the authorized person, allocation
 * oldest due first), SRS-INT-04 (one receipt per money), SRS-INT-07 (stage collection condition), SRS-FUL-09 /
 * SRS-FUL-10 (custom delivery with receiver and proof, no manufactured stock).
 *
 * Every business action goes through HTTP as its actor: staff on the web session, the customer on the portal guard.
 * Fixture setup (organisation, members, catalogue, limits) uses the existing helpers.
 */

use App\Enums\ActionStatus;
use App\Enums\StageStatus;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementDocument;
use App\Models\AuditEvent;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Inventory\StockMovement;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StateTransition;
use App\Models\Task;
use App\Models\User;
use App\Workflow\StageEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';
require_once __DIR__.'/../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = accI02Fixture();
});

it('runs a custom order from opening without price to delivery on the same single order', function () {
    $f = $this->f;
    $year = now()->format('Y');

    // 1) Open without price (COM-08): sales opens the custom order; nothing is priced, no quote, no measurement yet.
    $created = $this->actingAs($f->sales)->postJson('/orders', [
        'idempotency_key' => (string) Str::uuid(),
        'customer_id' => $f->customer->id,
        'branch_id' => $f->customer->branch_id,
        'custom' => ['need_summary' => 'مطبخ كامل مع جزيرة', 'needs_measurement' => true, 'lines' => [
            ['type' => 'custom_product', 'product_id' => $f->product->id, 'quantity' => '1'],
        ]],
    ])->assertCreated();
    expect($created->json('orders'))->toHaveCount(1);
    $order = Order::query()->sole();
    expect($order->status)->toBe('in_progress')
        ->and($order->number)->not->toBeEmpty()
        ->and(AgreementDocument::query()->count())->toBe(0)
        ->and(Agreement::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'order.created')->where('subject_id', $order->id)->count())->toBe(1);

    // 2) Measurement stage: only the required work (needs, final measurement) is executed; the optional site photos
    //    stay open and do not block the stage.
    expect(accI02ActiveStage($order))->toBe('measure');
    $measure = taskOf($order, 'measure');
    $this->actingAs($f->sales)->postJson("/tasks/{$measure->id}/measurements", [
        'location' => 'جدار المطبخ', 'name' => 'العرض', 'value' => '3.25', 'unit' => 'm', 'kind' => 'final', 'measured_on' => now()->toDateString(),
    ])->assertCreated();
    accI02DoTask($f->sales, $measure);
    accI02DoTask($f->sales, taskOf($order, 'needs'));
    expect(statusOf(actionOf($order, 'site_photos')))->not->toBe(ActionStatus::Verified)
        ->and(statusOf(actionOf($order, 'design')))->toBe(ActionStatus::NotRequired);
    accI02CompleteStage($f->manager, $order, 'measure');
    expect(taskOf($order, 'site_photos')->status->value)->toBe('assigned');

    // 3) Design and quote stage: the quote is prepared inside the SAME order and sent.
    expect(accI02ActiveStage($order))->toBe('design');
    $doc = $this->actingAs($f->sales)->postJson("/orders/{$order->id}/agreement/quotes", ['idempotency_key' => agrKey()])
        ->assertCreated()->json('document_id');
    $quote = AgreementDocument::query()->findOrFail($doc);
    $this->putJson("/agreement/documents/{$quote->id}", [
        ...agrQuoteData(lines: [finLine('مطبخ مع جزيرة', '20000')]), 'idempotency_key' => agrKey(), 'lock_version' => $quote->lock_version,
    ])->assertOk()->assertJsonPath('agreement.documents.0.content.totals.total_minor', 2_000_000);
    $this->postJson("/agreement/documents/{$quote->id}/send", ['idempotency_key' => agrKey(), 'lock_version' => $quote->refresh()->lock_version])
        ->assertOk()->assertJsonPath('agreement.documents.0.status', 'awaiting_customer');
    expect(statusOf(actionOf($order, 'quote')))->toBe(ActionStatus::Verified);
    accI02CompleteStage($f->manager, $order, 'design');

    // 4) Agreement stage: the customer approves the quote from their own account (P-67) — on the same order.
    expect(accI02ActiveStage($order))->toBe('agreement');
    Auth::forgetGuards();
    actingAsCustomer($f->customer);
    $quote->refresh();
    $this->post("/portal/documents/{$quote->id}/approve", ['content_hash' => $quote->content_hash])
        ->assertRedirect("/portal/documents/{$quote->id}");
    Auth::forgetGuards();

    $agreement = Agreement::query()->where('order_id', $order->id)->sole();
    expect($quote->refresh()->status->value)->toBe('approved')
        ->and($agreement->status->value)->toBe('active')
        ->and($agreement->total_minor)->toBe(2_000_000)
        ->and(Order::query()->count())->toBe(1)
        ->and(statusOf(actionOf($order, 'quote_approval')))->toBe(ActionStatus::Verified)
        ->and(accI02Dues($order))->toBe(['first' => [1_000_000, 0], 'final' => [1_000_000, 0]]);

    // Stage collection condition (INT-07): without the first 50% the agreement stage cannot be completed.
    $this->actingAs($f->manager)->postJson("/orders/{$order->id}/stages/".stageOf($order, 'agreement')->id.'/complete', [
        'lock_version' => $order->refresh()->lock_version,
    ])->assertStatus(409);
    expect(stageOf($order, 'agreement')->status)->toBe(StageStatus::InProgress);

    // 5) Collection: finance records the first payment → one receipt RC-YYYY-000001, allocated to the oldest due.
    $first = accI02Pay($this, $f->finance, $order, '10000.00', 'FT-I02-0001')->assertCreated();
    expect($first->json('receipt'))->toBe("RC-{$year}-000001")
        ->and($first->json('finance.summary.collected_minor'))->toBe(1_000_000)
        ->and(accI02Dues($order))->toBe(['first' => [1_000_000, 1_000_000], 'final' => [1_000_000, 0]])
        ->and(statusOf(actionOf($order, 'first_payment')))->toBe(ActionStatus::Verified);
    accI02CompleteStage($f->manager, $order, 'agreement');

    // 6) Execution: manufacturing then quality check (ordered), the optional materials follow-up never blocks.
    expect(accI02ActiveStage($order))->toBe('manufacture');
    accI02DoTask($f->sales, taskOf($order, 'manufacturing'));
    accI02DoTask($f->sales, taskOf($order, 'quality_check'));
    accI02CompleteStage($f->manager, $order, 'manufacture');

    // 7) Installation, final payment and delivery of the custom order (FUL-09/FUL-10).
    expect(accI02ActiveStage($order))->toBe('install');
    accI02DoTask($f->sales, taskOf($order, 'installation'));
    $second = accI02Pay($this, $f->finance, $order, '10000.00', 'FT-I02-0002', ['acknowledge_similar' => '1'])->assertCreated();
    expect($second->json('receipt'))->toBe("RC-{$year}-000002")
        ->and(accI02Dues($order))->toBe(['first' => [1_000_000, 1_000_000], 'final' => [1_000_000, 1_000_000]])
        ->and(statusOf(actionOf($order, 'final_payment')))->toBe(ActionStatus::Verified);

    $movements = StockMovement::query()->count();
    $line = (int) $order->activeLines()->firstOrFail()->id;
    $draft = $this->actingAs($f->keeper)->postJson("/orders/{$order->id}/deliveries", [
        'idempotency_key' => (string) Str::uuid(), 'lines' => [['order_line_id' => $line, 'quantity' => '1']],
    ])->assertCreated()->json('note');
    $note = $this->post("/deliveries/{$draft['id']}/confirm", [
        'idempotency_key' => (string) Str::uuid(), 'lock_version' => $draft['lock_version'], 'delivered_on' => now()->toDateString(),
        'receiver_name' => 'خالد العتيبي', 'proofs' => [UploadedFile::fake()->image('receipt.png', 80, 80)],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('note.status', 'confirmed')->json('note');
    expect($note['number'])->toBe("DN-{$year}-000001")
        ->and(actionOf($order, 'delivery')->source_reference)->toBe(['type' => 'delivery_note', 'id' => $draft['id']])
        ->and(statusOf(actionOf($order, 'delivery')))->toBe(ActionStatus::Verified)
        ->and(StockMovement::query()->count())->toBe($movements);

    // Completing the last stage finishes execution (M-04-T03); the money is fully collected.
    accI02CompleteStage($f->manager, $order, 'install');
    expect($order->refresh()->status)->toBe('execution_finished')
        ->and(OrderStage::query()->where('order_id', $order->id)->where('status', '!=', StageStatus::Completed->value)->count())->toBe(0);

    // One order, one quote, one agreement, two receipts, all transitions and audits recorded once each.
    expect(Order::query()->count())->toBe(1)
        ->and(AgreementDocument::query()->where('kind', 'quote')->count())->toBe(1)
        ->and(Agreement::query()->count())->toBe(1)
        ->and((int) Payment::query()->where('order_id', $order->id)->sum('amount_minor'))->toBe(2_000_000)
        ->and(Receipt::query()->orderBy('id')->pluck('number')->all())->toBe(["RC-{$year}-000001", "RC-{$year}-000002"])
        ->and(StateTransition::query()->where('machine', 'M-04')->where('transition', 'T03')->where('subject_id', $order->id)->count())->toBe(1)
        ->and(StateTransition::query()->where('machine', 'M-06')->where('transition', 'T04')->count())->toBe(5)
        ->and(accI02Audits($order, ['order.created', 'agreement_document.sent', 'agreement_document.approved', 'agreement.activated', 'finance.payment_recorded', 'delivery.confirmed', 'order.stage_completed', 'order.execution_finished']))
        ->toBe([
            'agreement.activated' => 1, 'agreement_document.approved' => 1, 'agreement_document.sent' => 1, 'delivery.confirmed' => 1,
            'finance.payment_recorded' => 2, 'order.created' => 1, 'order.execution_finished' => 1, 'order.stage_completed' => 5,
        ]);

    // The customer sees the same single order, fully paid.
    actingAsCustomer($f->customer);
    $this->get('/portal/orders')->assertOk();
    $this->get("/portal/orders/{$order->id}")->assertOk();
});

it('closes the custom order comprehensively once execution, money and delivery are settled (M-04-T07)', function () {
    // The SRS states-and-transitions table defines M-04-T07 «إقفال شامل» (P-18 / P-25) from execution_finished to
    // closed. No Action, route or screen implements it yet (ADR-0020 «Not in this phase»), so the order of the row above
    // stays `execution_finished` forever.
    expect(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => str_contains((string) $r->getName(), 'orders.progress.close')
        || str_contains($r->uri(), '/close')))->toBeTrue();
})->skip('Acceptance gap: no M-04-T07 comprehensive closure for a custom order');

it('refuses the order\'s business writes to a member without the permission, without effect', function () {
    $f = $this->f;
    $this->actingAs($f->sales)->postJson('/orders', [
        'idempotency_key' => (string) Str::uuid(), 'customer_id' => $f->customer->id, 'branch_id' => $f->customer->branch_id,
        'custom' => ['need_summary' => 'مطبخ', 'lines' => [['type' => 'custom_product', 'product_id' => $f->product->id, 'quantity' => '1']]],
    ])->assertCreated();
    $order = Order::query()->sole();
    $before = [AuditEvent::query()->count(), StateTransition::query()->count(), AgreementDocument::query()->count(), Payment::query()->count()];

    // Sales may not complete a stage (P-25), nor record money (P-39); the executor cannot prepare a quote (P-30).
    $this->actingAs($f->sales)->postJson("/orders/{$order->id}/stages/".stageOf($order, 'measure')->id.'/complete', ['lock_version' => $order->lock_version])
        ->assertForbidden();
    $this->actingAs($f->executor)->postJson("/orders/{$order->id}/agreement/quotes", ['idempotency_key' => agrKey()])->assertForbidden();
    $this->actingAs($f->sales)->post("/orders/{$order->id}/finance/payments", [
        'amount' => '100', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-NOPE', 'idempotency_key' => agrKey(),
        'evidence' => agrPdf(),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect([AuditEvent::query()->count(), StateTransition::query()->count(), AgreementDocument::query()->count(), Payment::query()->count()])->toBe($before);
});

/**
 * Fixture: the orders fixture (Org, sales in A, a published custom product) plus a manager (P-25), a finance officer
 * (P-39), an inventory keeper (P-52) and an executor of branch A; the customer has workspace login enabled.
 */
function accI02Fixture(): object
{
    $f = ordersFixture();
    $org = $f->org;
    $f->owner = $org->owner;
    $f->manager = $org->member('manager', [$org->a]);
    $f->finance = $org->member('finance', [$org->a]);
    $f->keeper = $org->member('inventory', [$org->a]);
    $f->executor = $org->member('executor', [$org->a], $org->d1);
    $f->customer = $f->inA;
    $f->customer->forceFill(['portal_login_enabled' => true])->save();
    agrLimit('sales', 1000);
    Auth::forgetGuards();

    return $f;
}

function accI02ActiveStage(Order $order): ?string
{
    return app(StageEngine::class)->activeStage($order->refresh())?->key;
}

/**
 * The holder starts and completes their task over HTTP.
 */
function accI02DoTask(User $holder, Task $task): void
{
    $task->refresh();
    expect($task->assignee_id)->toBe($holder->id);
    test()->actingAs($holder)->postJson("/tasks/{$task->id}/start")->assertOk();
    test()->actingAs($holder)->postJson("/tasks/{$task->id}/complete", ['result_note' => 'تم'])->assertOk()->assertJsonPath('task.status', 'completed');
}

function accI02CompleteStage(User $actor, Order $order, string $key): void
{
    $stage = stageOf($order, $key);
    test()->actingAs($actor)->postJson("/orders/{$order->id}/stages/{$stage->id}/complete", ['lock_version' => $order->refresh()->lock_version])
        ->assertOk();
    expect($stage->refresh()->status)->toBe(StageStatus::Completed);
}

/**
 * @param  array<string, mixed>  $extra
 */
function accI02Pay(object $test, User $actor, Order $order, string $amount, string $reference, array $extra = []): TestResponse
{
    return $test->actingAs($actor)->withHeader('Idempotency-Key', 'acc-i02-'.Str::uuid())->post("/orders/{$order->id}/finance/payments", [
        'amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => $reference,
        'lock_version' => finVersion($order), 'evidence' => agrPdf('transfer.pdf', $reference), ...$extra,
    ], ['Accept' => 'application/json']);
}

/**
 * @return array<string, array{0: int, 1: int}> due reference → [amount, allocated] in halalas
 */
function accI02Dues(Order $order): array
{
    return array_map(fn (array $d) => [$d['amount'], $d['allocated']], finDues($order));
}

/**
 * @param  list<string>  $actions
 * @return array<string, int>
 */
function accI02Audits(Order $order, array $actions): array
{
    // Sorted in PHP: MariaDB's collation orders '.' and '_' differently from SQLite.
    $counts = AuditEvent::query()->whereIn('action', $actions)
        ->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action')
        ->map(fn ($n) => (int) $n)->all();
    ksort($counts, SORT_STRING);

    return $counts;
}
