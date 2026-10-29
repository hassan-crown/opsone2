<?php

/**
 * Mandatory integration scenario 14 («تغيير تصميم أو قيمة بعد اعتماد», SRS-AGR-06, SRS-AGR-07, P02) end to end over
 * HTTP: after approval the old design/agreement is kept as proof; approving the replacement binds to the exact content
 * shown (an older presentation or an old link is never signed); the previous agreement stays in force until the
 * replacement is approved, applies once, and only the affected work is held — unaffected work continues.
 *
 * Moving the order to manufacturing (earlier stages done) is fixture setup; every agreement/portal step is HTTP.
 */

use App\Enums\AgreementStatus;
use App\Enums\ChangeStatus;
use App\Enums\DocumentStatus;
use App\Enums\TaskStatus;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptanceMoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    $this->f->order->customer->forceFill(['portal_login_enabled' => true])->save();
});

function accMDesign(object $f, string $name, ?int $replaces = null): AgreementDocument
{
    $id = (int) accMPost($f->manager, "/orders/{$f->order->id}/agreement/designs", array_filter([
        'file' => accMPdf($name), 'description' => 'تصميم المطبخ '.$name, 'idempotency_key' => accMKey(), 'replaces_id' => $replaces,
    ], fn ($v) => $v !== null))->assertCreated()->json('document_id');
    $document = AgreementDocument::query()->findOrFail($id);
    test()->actingAs($f->sales)->postJson("/agreement/documents/{$id}/send", ['idempotency_key' => accMKey(), 'lock_version' => $document->lock_version])
        ->assertOk();

    return $document->refresh();
}

it('scenario 14 (design): the approved design is kept; the customer approves only the replacement content shown', function () {
    $f = $this->f;
    accMAgree($f, [finLine('مطبخ', '18450')]);

    $first = accMDesign($f, 'kitchen.pdf');
    actingAsCustomer($f->order->customer);
    test()->post("/portal/documents/{$first->id}/approve", ['content_hash' => $first->content_hash])->assertRedirect("/portal/documents/{$first->id}");
    expect($first->refresh()->status)->toBe(DocumentStatus::Approved);
    $approvedHash = $first->content_hash;

    // A changed design after approval: the approved one stays approved until the replacement is approved.
    $second = accMDesign($f, 'kitchen-v2.pdf', $first->id);
    expect($second->content_hash)->not->toBe($approvedHash)
        ->and($first->refresh()->status)->toBe(DocumentStatus::Approved);

    // Approving the replacement with the old content (old link / old screen) is refused without effect.
    actingAsCustomer($f->order->customer);
    test()->from("/portal/documents/{$second->id}")->post("/portal/documents/{$second->id}/approve", ['content_hash' => $approvedHash])
        ->assertSessionHasErrors('transition');
    expect($second->refresh()->status)->toBe(DocumentStatus::AwaitingCustomer)
        ->and(CustomerDecision::query()->where('subject_type', 'document')->where('subject_id', $second->id)->count())->toBe(0);

    // The exact content shown is approved: the old design becomes «replaced», its approval kept as proof.
    test()->post("/portal/documents/{$second->id}/approve", ['content_hash' => $second->content_hash])->assertRedirect("/portal/documents/{$second->id}");
    expect($second->refresh()->status)->toBe(DocumentStatus::Approved)
        ->and($first->refresh()->status)->toBe(DocumentStatus::Replaced)
        ->and($first->content_hash)->toBe($approvedHash)
        ->and(CustomerDecision::query()->where('subject_type', 'document')->where('subject_id', $first->id)->where('decision', 'approved')->value('content_hash'))->toBe($approvedHash)
        ->and(CustomerDecision::query()->where('subject_type', 'document')->where('subject_id', $second->id)->where('decision', 'approved')->value('method'))->toBe(CustomerDecision::METHOD_ACCOUNT);

    // The replaced design cannot be signed again from its old link.
    $decisions = CustomerDecision::query()->count();
    test()->from("/portal/documents/{$first->id}")->post("/portal/documents/{$first->id}/approve", ['content_hash' => $approvedHash]);
    expect(CustomerDecision::query()->count())->toBe($decisions)
        ->and($first->refresh()->status)->toBe(DocumentStatus::Replaced);
    expect(accMAudit('agreement_document.replaced'))->toHaveCount(1);
});

it('scenario 14 (value): 18,450 → 19,650 keeps v1 in force until approved, holds only installation, applies once for the content shown', function () {
    $f = $this->f;
    $v1 = accMAgree($f, [finLine('مطبخ', '18450')]);
    $v1Content = $v1->content;
    // Fixture: measurement, design/quote and agreement stages done; manufacturing is open.
    finishActiveStage($f->owner, $f->order);
    finishActiveStage($f->owner, $f->order);
    finishActiveStage($f->owner, $f->order);
    $manufacturing = taskOf($f->order, 'manufacturing');

    // Sales presents the change: installation affected, 2 days of delay.
    $change = accMChange($f->sales, $f->order, [finLine('مطبخ', '19650')], ['installation'], 2);
    expect($change->difference_minor)->toBe(120_000)
        ->and(actionOf($f->order, 'installation')->held_by_change_id)->toBe($change->id)
        ->and(actionOf($f->order, 'manufacturing')->held_by_change_id)->toBeNull();
    // The previous agreement stays in force.
    test()->actingAs($f->sales)->getJson("/orders/{$f->order->id}/agreement")->assertOk()
        ->assertJsonPath('agreement.agreement.version', 1)
        ->assertJsonPath('agreement.agreement.total_minor', 1_845_000);

    // Unaffected work continues: manufacturing starts while the change is pending.
    test()->actingAs($f->owner)->postJson("/tasks/{$manufacturing->id}/start", ['lock_version' => $manufacturing->refresh()->lock_version])->assertOk();
    expect($manufacturing->refresh()->status)->toBe(TaskStatus::InProgress);

    // The customer asks for another price; the change is re-presented at 19,800.
    $oldHash = $change->refresh()->content_hash;
    test()->actingAs($f->sales)->postJson("/agreement/changes/{$change->id}/revision", ['reason' => 'الكوارتز أغلى', 'lock_version' => $change->lock_version])->assertOk();
    $change->refresh();
    test()->actingAs($f->sales)->putJson("/agreement/changes/{$change->id}", [
        ...agrQuoteData(lines: [finLine('مطبخ', '19800')]), 'description' => 'تعديل البنود', 'affected' => ['installation'], 'delay_days' => 2,
        'idempotency_key' => accMKey(), 'lock_version' => $change->lock_version,
    ])->assertOk();
    test()->actingAs($f->sales)->postJson("/agreement/changes/{$change->id}/present", ['idempotency_key' => accMKey(), 'lock_version' => $change->refresh()->lock_version])
        ->assertOk();

    // An approval of the older presentation is refused without effect.
    accMApplyChange($f->owner, $change, $oldHash)->assertStatus(409)->assertJson(['condition' => 'content_changed']);
    expect(Agreement::query()->where('order_id', $f->order->id)->count())->toBe(1);

    // The approval of the content shown applies once.
    accMApplyChange($f->owner, $change)->assertOk()
        ->assertJsonPath('agreement.agreement.version', 2)
        ->assertJsonPath('agreement.agreement.total_minor', 1_980_000);
    accMApplyChange($f->owner, $change)->assertStatus(409)->assertJson(['condition' => 'not_from_applied']);

    $v1->refresh();
    expect($change->refresh()->status)->toBe(ChangeStatus::Applied)
        ->and(Agreement::query()->where('order_id', $f->order->id)->count())->toBe(2)
        ->and($v1->status)->toBe(AgreementStatus::Superseded)
        ->and($v1->total_minor)->toBe(1_845_000)
        ->and($v1->content)->toBe($v1Content)
        ->and(actionOf($f->order, 'installation')->held_by_change_id)->toBeNull()
        // Manufacturing was not redone.
        ->and($manufacturing->refresh()->status)->toBe(TaskStatus::InProgress)
        ->and(accMAudit('agreement_change.applied'))->toHaveCount(1)
        ->and(accMAudit('agreement_change.presented'))->toHaveCount(2);
});
