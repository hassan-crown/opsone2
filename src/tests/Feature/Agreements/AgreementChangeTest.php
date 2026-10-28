<?php

/**
 * Agreement change after approval (AGR-06, AGR-07, M-09, P02) and the agreement parts of SRS scenarios 10, 11, 13, 14
 * with the documents' numbers. Collections belong to P6b: what was collected is bound through CollectedAmounts.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M07Task\T02StartTask;
use App\Actions\StateMachines\M09Change\T00CreateChange;
use App\Actions\StateMachines\M09Change\T01PresentChange;
use App\Actions\StateMachines\M09Change\T02RequestChangeRevision;
use App\Actions\StateMachines\M09Change\T04ApplyChange;
use App\Actions\StateMachines\M09Change\T05WithdrawChange;
use App\Actions\StateMachines\M09Change\UpdateChangeDraft;
use App\Agreements\AgreementApplied;
use App\Agreements\CustomerActor;
use App\Agreements\CustomerDecisions;
use App\Agreements\OrderAgreement;
use App\Enums\AgreementStatus;
use App\Enums\ChangeStatus;
use App\Enums\DocumentStatus;
use App\Enums\TaskStatus;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementDocument;
use App\Models\Task;
use App\Money\Money;
use App\Support\StaleRecord;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AgreementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = agreementFixture();
});

function agrLine(string $description, string $price, bool $products = true): array
{
    return ['description' => $description, 'quantity' => '1', 'unit_price' => $price, 'commissionable' => $products];
}

it('AGR-06: lowering 20,000 to 18,000 after collecting 19,000 leaves 1,000 to settle, with no automatic refund', function () {
    agrAgreed($this->f, [agrLine('مطبخ', '20000')]);
    agrCollected(1_900_000);
    Event::fake([AgreementApplied::class]);

    $agreement = agrApplyChange($this->f->owner, agrChange($this->f->sales, $this->f->order, [agrLine('مطبخ', '18000')]));

    expect($agreement->version)->toBe(2)
        ->and($agreement->total_minor)->toBe(1_800_000)
        ->and($agreement->previous_total_minor)->toBe(2_000_000)
        ->and($agreement->difference_minor)->toBe(-200_000)
        ->and($agreement->settlement_needed_minor)->toBe(100_000)
        ->and($this->f->order->refresh()->total_minor)->toBe(1_800_000);
    Event::assertDispatched(AgreementApplied::class, fn (AgreementApplied $e) => $e->agreementId === $agreement->id && $e->settlementNeededMinor === 100_000);
    // Lowering the agreement never creates a refund by itself; a refund is requested and executed in P6c.
    expect(DB::table('refunds')->count())->toBe(0);
});

it('creates a new version on change and keeps the previous one unchanged as proof', function () {
    $v1 = agrAgreed($this->f, [agrLine('مطبخ', '20000')]);
    $snapshot = $v1->getAttributes();
    agrApplyChange($this->f->owner, agrChange($this->f->sales, $this->f->order, [agrLine('مطبخ', '21200')], delayDays: 2));

    $v1->refresh();
    expect($v1->status)->toBe(AgreementStatus::Superseded)
        ->and($v1->total_minor)->toBe($snapshot['total_minor'])
        ->and($v1->content)->toBe(json_decode($snapshot['content'], true))
        ->and(OrderAgreement::current($this->f->order)->version)->toBe(2)
        ->and(OrderAgreement::history($this->f->order)->pluck('version')->all())->toBe([1, 2])
        // The approved quote is kept, marked replaced (M-08-T07); its approval is not erased.
        ->and(AgreementDocument::query()->sole()->status)->toBe(DocumentStatus::Replaced);
});

it('keeps the previous agreement in force until approval, and applies once', function () {
    agrAgreed($this->f, [agrLine('مطبخ', '20000')]);
    $change = agrChange($this->f->sales, $this->f->order, [agrLine('مطبخ', '21200')]);
    expect(OrderAgreement::value($this->f->order)->minor)->toBe(2_000_000)
        ->and($change->difference_minor)->toBe(120_000);

    $key = agrKey();
    agrApplyChange($this->f->owner, $change, $key);
    agrRefused(fn () => agrApplyChange($this->f->owner, $change->refresh()), InvalidTransition::class, 'not_from_applied');
    expect(Agreement::query()->count())->toBe(2);
});

it('refuses approving an older presentation after the change was presented again (concurrent change)', function () {
    agrAgreed($this->f, [agrLine('مطبخ', '20000')]);
    $change = agrChange($this->f->sales, $this->f->order, [agrLine('مطبخ', '21200')]);
    $oldHash = $change->content_hash;
    app(T02RequestChangeRevision::class)->handle($this->f->sales, $change, 'الكوارتز أغلى', $change->lock_version);
    $change = app(UpdateChangeDraft::class)->handle($this->f->sales, $change->refresh(), [
        ...agrQuoteData(lines: [agrLine('مطبخ', '21500')]), 'description' => 'تعديل', 'affected' => [], 'delay_days' => 0,
    ], $change->lock_version, agrKey());
    $change = app(T01PresentChange::class)->handle($this->f->sales, $change, $change->lock_version, agrKey());

    expect($change->presentations)->toHaveCount(2);
    agrRefused(fn () => app(T04ApplyChange::class)->handle($this->f->owner, $change, [
        'channel' => 'phone', 'decided_on' => now()->toDateString(), 'content_hash' => $oldHash,
    ], agrPdf(), $change->lock_version, agrKey()), InvalidTransition::class, 'content_changed');
    agrRefused(fn () => app(T04ApplyChange::class)->handle($this->f->owner, $change, [
        'channel' => 'phone', 'decided_on' => now()->toDateString(), 'content_hash' => $change->content_hash,
    ], agrPdf(), 1, agrKey()), StaleRecord::class);
});

it('scenario 10: deposit then an agreement of products 1,000 in a total of 1,200 (prices inclusive)', function () {
    $agreement = agrAgreed($this->f, [agrLine('مطبخ', '1000'), agrLine('تركيب ونقل', '200', false)]);

    expect($agreement->total_minor)->toBe(120_000)
        ->and($agreement->products_minor)->toBe(100_000)
        ->and(OrderAgreement::productsValue($this->f->order)->minor)->toBe(100_000);
});

it('scenario 11: lowering to products 500 in a total of 600 after collecting 900 → 300 to settle, applied once', function () {
    agrAgreed($this->f, [agrLine('مطبخ', '1000'), agrLine('تركيب ونقل', '200', false)]);
    agrCollected(90_000);
    $agreement = agrApplyChange($this->f->owner, agrChange($this->f->sales, $this->f->order, [agrLine('مطبخ', '500'), agrLine('تركيب ونقل', '100', false)]));

    expect($agreement->total_minor)->toBe(60_000)
        ->and($agreement->products_minor)->toBe(50_000)
        ->and($agreement->difference_minor)->toBe(-60_000)
        ->and($agreement->settlement_needed_minor)->toBe(30_000);
});

it('scenario 13: 50% then 100% are shares of the same agreed value', function () {
    agrAgreed($this->f, [agrLine('مطبخ', '1000'), agrLine('تركيب ونقل', '200', false)]);

    expect(OrderAgreement::share($this->f->order, 5000)->minor)->toBe(60_000)
        ->and(OrderAgreement::share($this->f->order, 10000)->minor)->toBe(120_000)
        // An odd value splits half-up: 1,200.01 × 50% = 600.005 → 600.01.
        ->and(Money::of(120_001)->percent(5000)->minor)->toBe(60_001);
});

it('scenario 14: a design or value change after approval keeps the old one, holds only the affected work and never the rest', function () {
    agrAgreed($this->f, [agrLine('مطبخ', '18450')]);
    // Reach manufacturing: the earlier stages' results are recorded (the quote ones by this agreement).
    finishActiveStage($this->f->owner, $this->f->order);
    finishActiveStage($this->f->owner, $this->f->order);
    finishActiveStage($this->f->owner, $this->f->order);
    $manufacturing = taskOf($this->f->order, 'manufacturing');
    expect($manufacturing->status)->not->toBe(TaskStatus::Unassigned);

    // The island changes: installation (later) is affected; manufacturing of the rest continues.
    $change = agrChange($this->f->sales, $this->f->order, [agrLine('مطبخ', '19650')], ['installation'], 2);
    expect(actionOf($this->f->order, 'installation')->refresh()->held_by_change_id)->toBe($change->id)
        ->and(actionOf($this->f->order, 'manufacturing')->refresh()->held_by_change_id)->toBeNull();
    app(T02StartTask::class)->handle($this->f->owner, $manufacturing->refresh());
    expect($manufacturing->refresh()->status)->toBe(TaskStatus::InProgress);

    // Customer approves the replacement content from their account (AGR-05): applied once, the hold released.
    $customer = new CustomerActor($this->f->order->customer_id, $this->f->order->customer->mobile);
    app(CustomerDecisions::class)->approveChange($customer, $change->refresh(), (string) $change->content_hash);
    expect(actionOf($this->f->order, 'installation')->refresh()->held_by_change_id)->toBeNull()
        ->and(OrderAgreement::value($this->f->order)->minor)->toBe(1_965_000)
        ->and($change->refresh()->status)->toBe(ChangeStatus::Applied);
});

it('refuses starting work held by a pending change, and releases it when the change is withdrawn', function () {
    agrAgreed($this->f, [agrLine('مطبخ', '18450')]);
    finishActiveStage($this->f->owner, $this->f->order);
    finishActiveStage($this->f->owner, $this->f->order);
    finishActiveStage($this->f->owner, $this->f->order);
    $task = taskOf($this->f->order, 'manufacturing');

    $change = agrChange($this->f->sales, $this->f->order, [agrLine('مطبخ', '19650')], ['manufacturing']);
    expectRefusedWithoutEffect(fn () => app(T02StartTask::class)->handle($this->f->owner, $task->refresh()), InvalidTransition::class, 'held_by_agreement_change');

    app(T05WithdrawChange::class)->handle($this->f->sales, $change->refresh(), 'العميل عدل عن التغيير', $change->lock_version);
    expect($change->refresh()->status)->toBe(ChangeStatus::Cancelled)
        ->and(OrderAgreement::value($this->f->order)->minor)->toBe(1_845_000);
    app(T02StartTask::class)->handle($this->f->owner, $task->refresh());
    expect(Task::query()->find($task->id)->status)->toBe(TaskStatus::InProgress);
});

it('refuses a change without an agreement, a second open change and content equal to the agreement', function () {
    agrRefused(fn () => app(T00CreateChange::class)->handle($this->f->sales, $this->f->order, 'x', agrKey()), InvalidTransition::class, 'no_agreement');
    agrAgreed($this->f, [agrLine('مطبخ', '20000')]);
    $change = app(T00CreateChange::class)->handle($this->f->sales, $this->f->order, 'x', agrKey());
    agrRefused(fn () => app(T00CreateChange::class)->handle($this->f->sales, $this->f->order, 'y', agrKey()), InvalidTransition::class, 'change_open');
    agrRefused(fn () => app(T01PresentChange::class)->handle($this->f->sales, $change, $change->lock_version, agrKey()), InvalidTransition::class, 'no_change');
});

function Schema(): Builder
{
    return Schema::getFacadeRoot();
}
