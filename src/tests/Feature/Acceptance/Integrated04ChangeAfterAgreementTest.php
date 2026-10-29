<?php

/**
 * «قبول النظام المتكامل» — row «تعديل بعد اتفاق»: «حفظ السابق وإيقاف الجزء المتأثر وتطبيق فرق المال مرة واحدة دون رد آلي».
 * With the binding integration rows «تغيير تصميم أو قيمة بعد اعتماد» (SRS-AGR-06, SRS-AGR-07) and the money side of
 * «تخفيض الاتفاق السابق …» (SRS-INT-05), and the «قرارات تنفيذية مبسطة» rule that agreement events are re-read from their
 * current source (SRS-INT-01..08). Also SRS-AGR-09 (re-approval never creates another agreement), SRS-FIN-09 (lowering
 * the agreement alone refunds nothing).
 *
 * Every business action goes through HTTP as the right actor (sales prepares, the owner records the customer's external
 * approval with evidence, finance records the payment). Fixture only: the facility/users/roles (financeFixture) and
 * walking the earlier workflow stages to manufacturing (finishActiveStage — the stage results come from P5/P6 sources
 * that have no single HTTP route here).
 */

use App\Enums\AgreementStatus;
use App\Enums\ChangeStatus;
use App\Enums\DocumentStatus;
use App\Enums\TaskStatus;
use App\Finance\MoneyLedger;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Finance\Receipt;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

it('keeps the previous version, holds only the affected work, applies the money difference once and refunds nothing', function () {
    $f = $this->f;
    $order = $f->order;
    $year = now()->format('Y');

    // Agreement v1 of 20,000.00 (VAT-inclusive) approved externally; schedule 50% / rest.
    $v1 = accI04Agree($this, $f, [finLine('مطبخ', '20000')]);
    expect($v1->version)->toBe(1)->and($v1->total_minor)->toBe(2_000_000)
        ->and(finDues($order))->toBe(['first' => ['amount' => 1_000_000, 'allocated' => 0], 'final' => ['amount' => 1_000_000, 'allocated' => 0]]);

    // First payment of 10,000.00 collected over HTTP: one receipt, allocated to the first due.
    $this->actingAs($f->finance)->post("/orders/{$order->id}/finance/payments", [
        'amount' => '10000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-I04-0001',
        'lock_version' => finVersion($order), 'idempotency_key' => accI04Key(), 'evidence' => accI04Pdf('transfer'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('receipt', "RC-{$year}-000001");
    expect(MoneyLedger::collected($order->id)->minor)->toBe(1_000_000)
        ->and(finDues($order)['first'])->toBe(['amount' => 1_000_000, 'allocated' => 1_000_000]);

    // Fixture: walk measurement, design/quote and agreement stages to manufacturing.
    finishActiveStage($f->owner, $order);
    finishActiveStage($f->owner, $order);
    finishActiveStage($f->owner, $order);
    $manufacturing = taskOf($order, 'manufacturing');
    expect($manufacturing->status)->not->toBe(TaskStatus::Unassigned);

    // The change: lower to 8,000.00 (below the 10,000.00 collected), affecting installation only.
    $change = accI04Change($this, $f, [finLine('مطبخ', '8000')], ['installation']);
    expect($change->status)->toBe(ChangeStatus::AwaitingApproval)
        ->and($change->difference_minor)->toBe(-1_200_000)
        // Only the affected part is held while the change is open; the rest goes on.
        ->and(actionOf($order, 'installation')->held_by_change_id)->toBe($change->id)
        ->and(actionOf($order, 'manufacturing')->held_by_change_id)->toBeNull()
        ->and(DB::table('order_actions')->where('order_id', $order->id)->whereNotNull('held_by_change_id')->count())->toBe(1);
    $this->actingAs($f->owner)->postJson("/tasks/{$manufacturing->id}/start", ['lock_version' => $manufacturing->refresh()->lock_version])->assertOk();
    expect($manufacturing->refresh()->status)->toBe(TaskStatus::InProgress);

    // The previous agreement stays in force until approval: money untouched.
    expect(Agreement::query()->where('order_id', $order->id)->where('status', 'active')->sole()->id)->toBe($v1->id)
        ->and($order->refresh()->total_minor)->toBe(2_000_000)
        ->and(array_sum(array_column(finDues($order), 'amount')))->toBe(2_000_000);
    $v1Before = DB::table('agreements')->where('id', $v1->id)->first();

    // The customer's external approval of the presented content, recorded with evidence (P-36 + P-34).
    $applyKey = accI04Key();
    $readVersion = $change->lock_version;
    $presentedHash = (string) $change->content_hash;
    $apply = fn (string $key) => $this->actingAs($f->owner)->post("/agreement/changes/{$change->id}/apply", [
        'idempotency_key' => $key, 'lock_version' => $readVersion, 'channel' => 'phone',
        'decided_on' => now()->toDateString(), 'content_hash' => $presentedHash, 'evidence' => accI04Pdf('approval'),
    ], ['Accept' => 'application/json']);
    $apply($applyKey)->assertOk()->assertJsonPath('agreement.agreement.version', 2);

    $v2 = Agreement::query()->where('order_id', $order->id)->where('status', 'active')->sole();
    $v1After = DB::table('agreements')->where('id', $v1->id)->first();
    expect($v2->version)->toBe(2)
        ->and($v2->total_minor)->toBe(800_000)
        ->and($v2->previous_total_minor)->toBe(2_000_000)
        ->and($v2->difference_minor)->toBe(-1_200_000)
        // Surplus over the new total is visible for a decision, never refunded by itself.
        ->and($v2->settlement_needed_minor)->toBe(200_000)
        // The previous version is kept unchanged as proof, only marked superseded.
        ->and($v1->refresh()->status)->toBe(AgreementStatus::Superseded)
        ->and($v1After->total_minor)->toBe($v1Before->total_minor)
        ->and($v1After->content)->toBe($v1Before->content)
        ->and(AgreementDocument::query()->where('order_id', $order->id)->sole()->status)->toBe(DocumentStatus::Replaced)
        ->and($change->refresh()->status)->toBe(ChangeStatus::Applied)
        // The hold is released with the decision.
        ->and(DB::table('order_actions')->where('order_id', $order->id)->whereNotNull('held_by_change_id')->count())->toBe(0)
        ->and($order->refresh()->total_minor)->toBe(800_000);

    // The schedule follows the new agreement once; collected money is untouched and the excess is visible.
    $afterFirstApply = accI04MoneyState($order);
    expect(array_sum(array_column(finDues($order), 'amount')))->toBe(800_000)
        ->and(MoneyLedger::collected($order->id)->minor)->toBe(1_000_000)
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'agreement_change.applied')->count())->toBe(1);
    $summary = $this->actingAs($f->finance)->getJson("/orders/{$order->id}/finance")->assertOk()->json('finance.summary');
    expect($summary['agreed_minor'])->toBe(800_000)
        ->and($summary['collected_minor'])->toBe(1_000_000)
        ->and($summary['remaining_minor'])->toBe(0)
        ->and($summary['excess_minor'])->toBe(200_000)
        ->and($summary['settlement_needed_minor'])->toBe(200_000);

    // A replay with the same key returns the known result without a second effect.
    $apply($applyKey)->assertOk()->assertJsonPath('agreement.agreement.version', 2);
    expect(Agreement::query()->where('order_id', $order->id)->count())->toBe(2)
        ->and(accI04MoneyState($order))->toBe($afterFirstApply)
        ->and(DB::table('audit_events')->where('action', 'agreement_change.applied')->count())->toBe(1)
        ->and(DB::table('state_transitions')->where('machine', 'M-09')->where('transition', 'T04')->count())->toBe(1);

    // Approving the same change again with a new key is refused (409) and changes nothing.
    $apply(accI04Key())->assertStatus(409);
    expect(Agreement::query()->where('order_id', $order->id)->count())->toBe(2)
        ->and(accI04MoneyState($order))->toBe($afterFirstApply)
        ->and(DB::table('refunds')->count())->toBe(0);
});

it('raises the agreement once: the schedule grows by the difference only and nothing is collected or refunded by itself', function () {
    $f = $this->f;
    $order = $f->order;
    accI04Agree($this, $f, [finLine('مطبخ', '18450')]);
    $this->actingAs($f->finance)->post("/orders/{$order->id}/finance/payments", [
        'amount' => '9225.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT-I04-0002',
        'lock_version' => finVersion($order), 'idempotency_key' => accI04Key(), 'evidence' => accI04Pdf('first'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $change = accI04Change($this, $f, [finLine('مطبخ', '19650')], ['installation'], 2);
    expect($change->difference_minor)->toBe(120_000);

    $key = accI04Key();
    foreach ([1, 2] as $attempt) {
        $this->actingAs($f->owner)->post("/agreement/changes/{$change->id}/apply", [
            'idempotency_key' => $key, 'lock_version' => $change->lock_version, 'channel' => 'paper',
            'decided_on' => now()->toDateString(), 'content_hash' => (string) $change->content_hash, 'evidence' => accI04Pdf('paper'),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    $v2 = Agreement::query()->where('order_id', $order->id)->where('status', 'active')->sole();
    expect($v2->version)->toBe(2)
        ->and($v2->total_minor)->toBe(1_965_000)
        ->and($v2->difference_minor)->toBe(120_000)
        ->and($v2->settlement_needed_minor)->toBe(0)
        ->and(array_sum(array_column(finDues($order), 'amount')))->toBe(1_965_000)
        ->and(MoneyLedger::collected($order->id)->minor)->toBe(922_500)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'agreement_change.applied')->count())->toBe(1);
});

it('refuses applying a change without P-36/P-34 and without evidence, leaving the previous agreement in force', function () {
    $f = $this->f;
    $order = $f->order;
    $v1 = accI04Agree($this, $f, [finLine('مطبخ', '20000')]);
    $change = accI04Change($this, $f, [finLine('مطبخ', '15000')], ['installation']);
    $body = [
        'idempotency_key' => accI04Key(), 'lock_version' => $change->lock_version, 'channel' => 'phone',
        'decided_on' => now()->toDateString(), 'content_hash' => (string) $change->content_hash,
    ];

    $this->actingAs($f->executor)->post("/agreement/changes/{$change->id}/apply", [...$body, 'evidence' => accI04Pdf('x')], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($f->owner)->post("/agreement/changes/{$change->id}/apply", $body, ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('evidence');
    $this->actingAs($f->owner)->post("/agreement/changes/{$change->id}/apply", [...$body, 'content_hash' => str_repeat('0', 64), 'evidence' => accI04Pdf('y')], ['Accept' => 'application/json'])
        ->assertStatus(409)->assertJsonPath('condition', 'content_changed');

    expect(Agreement::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($v1->refresh()->status)->toBe(AgreementStatus::Active)
        ->and($change->refresh()->status)->toBe(ChangeStatus::AwaitingApproval)
        ->and(actionOf($order, 'installation')->held_by_change_id)->toBe($change->id)
        ->and(DB::table('audit_events')->where('action', 'agreement_change.applied')->count())->toBe(0);
});

function accI04Key(): string
{
    return (string) Str::uuid();
}

function accI04Pdf(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent($body.'.pdf', "%PDF-1.4\n".$body);
}

/**
 * Quote → price → send by sales, external approval with evidence by the owner, all over HTTP.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function accI04Agree(mixed $test, object $f, array $lines): Agreement
{
    $order = $f->order;
    $id = (int) $test->actingAs($f->sales)->postJson("/orders/{$order->id}/agreement/quotes", ['idempotency_key' => accI04Key()])->assertCreated()->json('document_id');
    $quote = AgreementDocument::query()->findOrFail($id);
    $test->actingAs($f->sales)->putJson("/agreement/documents/{$id}", [...agrQuoteData(lines: $lines), 'idempotency_key' => accI04Key(), 'lock_version' => $quote->lock_version])->assertOk();
    $test->actingAs($f->sales)->postJson("/agreement/documents/{$id}/send", ['idempotency_key' => accI04Key(), 'lock_version' => $quote->refresh()->lock_version])
        ->assertOk()->assertJsonPath('agreement.documents.0.status', 'awaiting_customer');
    $quote->refresh();
    $test->actingAs($f->owner)->post("/agreement/documents/{$id}/external-approval", [
        'idempotency_key' => accI04Key(), 'lock_version' => $quote->lock_version, 'channel' => 'message',
        'decided_on' => now()->toDateString(), 'content_hash' => (string) $quote->content_hash, 'evidence' => accI04Pdf('agree'),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('agreement.agreement.version', 1);

    return Agreement::query()->where('order_id', $order->id)->where('status', 'active')->sole();
}

/**
 * Create → price → present a change by sales over HTTP; returns it awaiting approval.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  list<string>  $affected
 */
function accI04Change(mixed $test, object $f, array $lines, array $affected, int $delayDays = 0): AgreementChange
{
    $order = $f->order;
    $id = (int) $test->actingAs($f->sales)->postJson("/orders/{$order->id}/agreement/changes", ['idempotency_key' => accI04Key(), 'description' => 'تعديل البنود'])
        ->assertCreated()->json('change_id');
    $change = AgreementChange::query()->findOrFail($id);
    $test->actingAs($f->sales)->putJson("/agreement/changes/{$id}", [
        ...agrQuoteData(lines: $lines), 'description' => 'تعديل البنود', 'affected' => $affected, 'delay_days' => $delayDays,
        'idempotency_key' => accI04Key(), 'lock_version' => $change->lock_version,
    ])->assertOk();
    $test->actingAs($f->sales)->postJson("/agreement/changes/{$id}/present", ['idempotency_key' => accI04Key(), 'lock_version' => $change->refresh()->lock_version])->assertOk();

    return $change->refresh();
}

/**
 * Money rows that a replayed or refused apply must not touch.
 *
 * @return array<string, mixed>
 */
function accI04MoneyState(Order $order): array
{
    return [
        'agreements' => DB::table('agreements')->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($a) => [$a->id, $a->status, $a->total_minor])->all(),
        'dues' => DB::table('order_dues')->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($d) => [$d->id, $d->amount_minor, $d->removed_at])->all(),
        'journals' => DB::table('money_journals')->count(),
        'entries' => DB::table('money_entries')->count(),
        'receipts' => DB::table('receipts')->count(),
        'refunds' => DB::table('refunds')->count(),
        'decisions' => DB::table('customer_decisions')->count(),
        'order_total' => DB::table('orders')->where('id', $order->id)->value('total_minor'),
    ];
}
