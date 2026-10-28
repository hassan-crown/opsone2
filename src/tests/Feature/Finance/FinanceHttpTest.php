<?php

/**
 * Finance screens and endpoints (docs/p6-finance-api.md): FinOrder and its variants, FinRecordPayment,
 * FinReceiptReview, FinSchedule, FinHome, FinReminder, ReportCollection. Amounts never reach a viewer without P-37
 * and executors never reach order money at all; reminders respect the facility's reminder hours.
 */

use App\Finance\CollectionReminders;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Receipt;
use App\Models\User;
use App\Models\WorkSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/FinanceTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

/**
 * @param  array<array-key, mixed>  $payload
 * @return list<string>
 */
function finMinorKeys(array $payload): array
{
    $found = [];
    array_walk_recursive($payload, function ($v, $k) use (&$found) {
        if (is_string($k) && str_ends_with($k, '_minor')) {
            $found[] = $k;
        }
    });

    return $found;
}

it('renders the order finance page for finance and keeps executors out of order money', function () {
    finAgree($this->f, '18450');
    $this->actingAs($this->f->finance)->get("/orders/{$this->f->order->id}/finance")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('orders/finance')
            ->where('finance.amounts_visible', true)
            ->where('finance.summary.agreed_minor', 1_845_000)
            ->where('finance.can.record', true)
            ->has('finance.dues', 2));

    $this->actingAs($this->f->executor)->getJson("/orders/{$this->f->order->id}/finance")->assertForbidden();
    $this->actingAs($this->f->executor)->getJson('/finance')->assertForbidden();
    $this->actingAs($this->f->executor)->getJson('/reports/collection')->assertForbidden();
});

it('hides every amount from a viewer without P-37 (supervisor): statuses only, never 0', function () {
    finAgree($this->f, '18450');
    finPay($this->f->finance, $this->f->order, '5000');
    finProof($this->f->order, '1000', 'FT-SUP');

    $payload = $this->actingAs($this->f->supervisor)->getJson("/orders/{$this->f->order->id}/finance")->assertOk()->json('finance');
    expect($payload['amounts_visible'])->toBeFalse()
        ->and($payload['summary'])->toBeNull()
        ->and(finMinorKeys($payload))->toBe([])
        ->and($payload['dues'][0]['status'])->toBe('partially_paid')
        ->and($payload['payments'][0]['evidence'])->toBe([])
        ->and($payload['can'])->toBe(['record' => false, 'review' => false, 'schedule' => false, 'allocate' => false]);
});

it('never puts money in an executor\'s task payload', function () {
    finAgree($this->f, '18450');
    finToAgreementStage($this->f);
    finishActiveStage($this->f->owner, $this->f->order);
    $task = taskOf($this->f->order, 'manufacturing');
    $assignee = User::query()->findOrFail($task->refresh()->assignee_id);

    $response = $this->actingAs($assignee)->getJson("/tasks/{$task->id}");
    expect(finMinorKeys((array) $response->json()))->toBe([]);
});

it('records a payment over HTTP once (Idempotency-Key header), answering with the receipt and the refreshed money', function () {
    finAgree($this->f, '20000');
    $read = finVersion($this->f->order);
    $send = fn () => $this->actingAs($this->f->finance)->withHeader('Idempotency-Key', 'pay-key-0001')->post("/orders/{$this->f->order->id}/finance/payments", [
        'amount' => '10000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-HTTP-1',
        'lock_version' => $read, 'evidence' => UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.4\nsame"),
    ], ['Accept' => 'application/json']);

    $first = $send()->assertCreated();
    expect($first->json('receipt'))->toBe('RC-'.now()->format('Y').'-000001')
        ->and($first->json('finance.summary.collected_minor'))->toBe(1_000_000);
    $send()->assertCreated();
    expect(Receipt::query()->count())->toBe(1);

    $this->actingAs($this->f->finance)->postJson("/orders/{$this->f->order->id}/finance/payments", [
        'amount' => '1', 'method' => 'cash', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'pay-key-0002', 'lock_version' => 1,
        'evidence' => UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.4\nx"),
    ])->assertStatus(409)->assertJson(['code' => 'stale_version']);
    $this->actingAs($this->f->finance)->post("/orders/{$this->f->order->id}/finance/payments", [
        'amount' => '5', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-HTTP-1', 'idempotency_key' => 'pay-key-0003',
        'evidence' => UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.4\ny"),
    ], ['Accept' => 'application/json'])->assertStatus(409)->assertJson(['condition' => 'duplicate_reference']);
});

it('confirms a customer receipt, asks for a correction and edits the schedule over HTTP', function () {
    finAgree($this->f, '20000');
    $proof = finProof($this->f->order, '6000', 'FT-HTTP-P');
    $this->actingAs($this->f->finance)->postJson("/finance/proofs/{$proof->id}/confirm", [
        'idempotency_key' => 'proof-key-001', 'lock_version' => $proof->lock_version, 'amount' => '6000', 'paid_on' => now()->toDateString(),
    ])->assertOk()->assertJsonPath('finance.summary.collected_minor', 600_000);

    $second = finProof($this->f->order, '100', 'FT-HTTP-Q');
    $this->actingAs($this->f->finance)->postJson("/finance/proofs/{$second->id}/correction", ['reason' => 'الصورة غير واضحة', 'lock_version' => $second->lock_version])
        ->assertOk()->assertJsonPath('finance.proofs.0.status', 'needs_correction');

    $this->actingAs($this->f->finance)->putJson("/orders/{$this->f->order->id}/finance/schedule", [
        'idempotency_key' => 'sched-key-001', 'lock_version' => finVersion($this->f->order),
        'dues' => [
            ['name' => ['ar' => 'دفعة أولى'], 'reference' => 'first', 'basis' => 'amount', 'value' => '6000', 'event' => 'agreement'],
            ['name' => ['ar' => 'الباقي'], 'reference' => 'final', 'basis' => 'rest', 'value' => null, 'event' => 'payment_stage'],
        ],
    ])->assertOk()->assertJsonPath('finance.dues.0.status', 'paid')->assertJsonPath('finance.dues.1.amount_minor', 1_400_000);
});

it('lists due, overdue, receipts and unallocated money in FinHome, scoped by P-37 over each order', function () {
    finAgree($this->f, '20000');
    finProof($this->f->order, '500', 'FT-HOME');
    $other = openCustomOrder($this->f->owner, $this->f->inB);
    finPay($this->f->owner, $other, '700');

    $mine = $this->actingAs($this->f->finance)->getJson('/finance')->assertOk()->json();
    expect(collect($mine['items'])->pluck('kind')->sort()->values()->all())->toBe(['due', 'proof'])
        ->and(collect($mine['items'])->pluck('order.id')->unique()->all())->toBe([$this->f->order->id]);

    $all = $this->actingAs($this->f->owner)->getJson('/finance')->assertOk()->json();
    expect($all['counts'])->toBe(['overdue' => 0, 'due' => 1, 'proof' => 1, 'settlement' => 1]);

    $this->travel(10)->days();
    $later = $this->actingAs($this->f->owner)->getJson('/finance')->json();
    expect($later['counts']['overdue'])->toBe(1);
});

it('ReportCollection: ages dues from confirmed money only and sums this month\'s collections', function () {
    finAgree($this->f, '20000');
    finPay($this->f->finance, $this->f->order, '4000');
    finProof($this->f->order, '6000', 'FT-REP');
    $this->travel(9)->days();

    $report = $this->actingAs($this->f->owner)->getJson('/reports/collection')->assertOk()->json();
    expect($report['buckets']['d8_30'])->toBe(['amount_minor' => 600_000, 'count' => 1])
        ->and($report['overdue_total_minor'])->toBe(600_000)
        ->and($report['month']['collected_minor'])->toBe(CarbonImmutable::now()->day > 9 ? 400_000 : 0);
    $this->actingAs($this->f->sales)->getJson('/reports/collection')->assertForbidden();
});

it('previews and sends a bulk reminder with each customer\'s own amount; reminders run only inside the reminder hours', function () {
    finAgree($this->f, '20000');
    $due = OrderDue::query()->where('order_id', $this->f->order->id)->where('reference', 'first')->sole();
    $preview = $this->actingAs($this->f->finance)->postJson('/finance/reminders/preview', ['due_ids' => [$due->id]])->assertOk()->json('recipients');
    expect($preview[0]['amount_minor'])->toBe(1_000_000)->and($preview[0]['excluded'])->toBeNull();
    $this->actingAs($this->f->finance)->postJson('/finance/reminders', ['due_ids' => [$due->id]])->assertOk()->assertJson(['sent' => 1]);
    $this->actingAs($this->f->executor)->postJson('/finance/reminders', ['due_ids' => [$due->id]])->assertForbidden();

    $settings = WorkSettings::current();
    $settings->forceFill(['reminder_from' => '00:00', 'reminder_until' => '00:00'])->save();
    $this->travel(3)->days();
    expect(app(CollectionReminders::class)->runDue(now()))->toBe(0);
    $settings->forceFill(['reminder_from' => '00:00', 'reminder_until' => '23:59'])->save();
    expect(app(CollectionReminders::class)->runDue(now()))->toBe(1)
        ->and(app(CollectionReminders::class)->runDue(now()))->toBe(0)
        ->and(DB::table('outbox_messages')->where('idempotency_key', 'like', "due:{$due->id}:overdue:%")->count())->toBeGreaterThan(0);
});

it('answers 404 for online payment webhooks while no gateway is configured, without CSRF', function () {
    $this->postJson('/webhooks/payments/anypay', ['x' => 1])->assertNotFound();
});
