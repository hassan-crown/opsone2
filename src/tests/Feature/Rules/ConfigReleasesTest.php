<?php

use App\Actions\Rules\PublishConfigRelease;
use App\Actions\Rules\SaveDocumentClause;
use App\Actions\Rules\UpdateReminderSettings;
use App\Actions\StateMachines\M08Document\T00PrepareContract;
use App\Agreements\DocumentClauses;
use App\Configuration\ConfigReleases;
use App\Configuration\ConfigSnapshot;
use App\Enums\DocumentKind;
use App\Finance\Plans\PaymentPlans;
use App\Models\Agreements\DocumentClause;
use App\Models\AuditEvent;
use App\Models\ConfigRelease;
use App\Models\Finance\PaymentPlan;
use App\Models\MemberGrant;
use App\Models\Order;
use App\Models\ReminderSettings;
use App\Support\StaleRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/RulesTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

/**
 * The other order of a fixture, agreed for a value.
 */
function agreeOther(object $f, Order $order, string $total): void
{
    $g = clone $f;
    $g->order = $order;
    finAgree($g, $total);
}

function draftHash(): string
{
    return ConfigSnapshot::hash(ConfigSnapshot::capture());
}

/** @return list<string> */
function dueReferences(int $orderId): array
{
    return array_column(planDues($orderId), 'reference');
}

it('publishes the draft as numbered, immutable releases with who, when and notes; drafts apply only once published', function () {
    expect(ConfigReleases::latest())->toBeNull()
        ->and(ConfigReleases::current())->toBe(ConfigSnapshot::defaults());

    installmentPlan($this->f->owner);
    // A draft: the application still reads the built-in rules.
    expect(PaymentPlans::pending($this->f->order->id)['key'])->toBe(PaymentPlans::FALLBACK);

    $one = publishRules($this->f->owner, 'خطة الأقساط');
    expect($one->number)->toBe(1)
        ->and($one->notes)->toBe('خطة الأقساط')
        ->and($one->published_by)->toBe($this->f->owner->id)
        ->and($one->published_at)->not->toBeNull()
        ->and($one->snapshot['payment_plans'][0]['key'])->toBe('forty_forty')
        ->and(PaymentPlans::pending($this->f->order->id)['key'])->toBe('forty_forty');

    // Nothing new: refused. A changed draft: release 2.
    expect(fn () => publishRules($this->f->owner))->toThrow(ValidationException::class);
    app(UpdateReminderSettings::class)->handle($this->f->owner, [...ReminderSettings::DEFAULTS, 'before_days' => [5]], null);
    expect(ReminderSettings::current()->before_days)->toBe([2])->and(ReminderSettings::working()->before_days)->toBe([5]);
    $two = publishRules($this->f->owner);
    expect($two->number)->toBe(2)->and(ReminderSettings::current()->before_days)->toBe([5]);

    // Releases never change and are never deleted.
    expect(fn () => $one->refresh()->update(['notes' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $one->refresh()->delete())->toThrow(LogicException::class);

    $audit = AuditEvent::query()->where('action', 'config_release.published')->orderBy('id')->get();
    expect($audit)->toHaveCount(2)
        ->and($audit[1]->after['release'])->toBe(2)
        ->and($audit[1]->after['changes'])->toBe(['reminders' => 1]);
});

it('keeps running orders on the release they were created on; new orders take the current release', function () {
    installmentPlan($this->f->owner);
    $clause = app(SaveDocumentClause::class)->handle($this->f->owner, null, [
        'key' => 'warranty', 'title' => ['ar' => 'الضمان'], 'body' => ['ar' => 'ضمان سنة للطلب {order}.'], 'applies_to' => ['contract'], 'included_by_default' => true,
    ], null);
    $one = publishRules($this->f->owner);
    $early = openCustomOrder($this->f->sales, $this->f->inA);
    expect($early->config_release_id)->toBe($one->id);

    // Release 2: a 50/50 default instead, a new clause text and a new reminder policy.
    savePlan($this->f->owner, 'half_half', [planLine('first', 'percent', '50'), planLine('final', 'rest', null)], true);
    app(SaveDocumentClause::class)->handle($this->f->owner, $clause->refresh(), [
        'key' => 'warranty', 'title' => ['ar' => 'الضمان'], 'body' => ['ar' => 'ضمان سنتين للطلب {order}.'], 'applies_to' => ['contract'], 'included_by_default' => true,
    ], $clause->lock_version);
    app(UpdateReminderSettings::class)->handle($this->f->owner, [...ReminderSettings::DEFAULTS, 'before_days' => [1]], null);
    $two = publishRules($this->f->owner);
    $late = openCustomOrder($this->f->sales, $this->f->inA);
    expect($late->config_release_id)->toBe($two->id);

    // Money: each order is priced from its own release.
    agreeOther($this->f, $early, '10000');
    agreeOther($this->f, $late, '10000');
    expect(dueReferences($early->id))->toBe(['first', 'second', 'final'])
        ->and(array_column(planDues($early->id), 'amount'))->toBe([400_000, 400_000, 200_000])
        ->and(dueReferences($late->id))->toBe(['first', 'final'])
        ->and(array_column(planDues($late->id), 'amount'))->toBe([500_000, 500_000]);

    // Documents: the contract copies the clause of the order's release.
    $earlyContract = app(T00PrepareContract::class)->handle($this->f->sales, $early->refresh(), null, null, agrKey());
    $lateContract = app(T00PrepareContract::class)->handle($this->f->sales, $late->refresh(), null, null, agrKey());
    expect($earlyContract->content['clauses'][0])->toContain('سنة')->not->toContain('سنتين')
        ->and($earlyContract->content['clause_versions'])->toBe([['key' => 'warranty', 'version' => 1]])
        ->and($lateContract->content['clauses'][0])->toContain('سنتين')
        ->and($lateContract->content['clause_versions'])->toBe([['key' => 'warranty', 'version' => 2]]);

    // Choosing a plan: only the plans of the order's release.
    $halfHalf = PaymentPlan::query()->where('key', 'half_half')->sole();
    expect(collect(PaymentPlans::options($early))->pluck('key')->all())->toBe(['forty_forty'])
        ->and(PaymentPlans::inRelease($early, $halfHalf->id))->toBeNull()
        ->and(PaymentPlans::inRelease($late, $halfHalf->id)?->key)->toBe('half_half');

    // Communication follows the release in force for every order.
    expect(ReminderSettings::current()->before_days)->toBe([1]);

    // The pin never moves.
    expect(fn () => $early->refresh()->forceFill(['config_release_id' => $two->id])->save())->toThrow(LogicException::class);
});

it('lets an order created before the first release follow the release in force', function () {
    expect($this->f->order->config_release_id)->toBeNull();
    installmentPlan($this->f->owner);
    publishRules($this->f->owner);
    finAgree($this->f, '10000');
    expect(dueReferences($this->f->order->id))->toBe(['first', 'second', 'final']);
});

it('rolls back by publishing a copy of an older release, never changing or deleting history', function () {
    installmentPlan($this->f->owner);
    $clause = app(SaveDocumentClause::class)->handle($this->f->owner, null, [
        'key' => 'site', 'title' => ['ar' => 'الموقع'], 'body' => ['ar' => 'لا يشمل الكهرباء.'], 'applies_to' => ['quote'], 'included_by_default' => true,
    ], null);
    $one = publishRules($this->f->owner);

    $half = savePlan($this->f->owner, 'half_half', [planLine('first', 'percent', '50'), planLine('final', 'rest', null)], true);
    app(SaveDocumentClause::class)->handle($this->f->owner, $clause->refresh(), [
        'key' => 'site', 'title' => ['ar' => 'الموقع'], 'body' => ['ar' => 'لا يشمل الكهرباء ولا السباكة.'], 'applies_to' => ['quote'], 'included_by_default' => true,
    ], $clause->lock_version);
    $two = publishRules($this->f->owner);
    $oneHash = $one->snapshot_hash;
    $twoSnapshot = $two->snapshot;

    $three = app(PublishConfigRelease::class)->rollback($this->f->owner, $one, 'رجوع لخطة الأقساط', 2);
    expect($three->number)->toBe(3)
        ->and($three->restored_from_id)->toBe($one->id)
        ->and($three->snapshot_hash)->toBe($oneHash)
        ->and($one->refresh()->snapshot_hash)->toBe($oneHash)
        ->and($two->refresh()->snapshot)->toBe($twoSnapshot)
        ->and(ConfigRelease::query()->count())->toBe(3);

    // The draft is release 1 again: the 50/50 plan archived (not deleted), the clause back on version 1.
    expect(PaymentPlan::query()->whereKey($half->id)->value('archived_at'))->not->toBeNull()
        ->and(PaymentPlan::query()->active()->where('is_default', true)->value('key'))->toBe('forty_forty')
        ->and($clause->refresh()->version)->toBe(1)
        ->and($clause->body->get('ar'))->toBe('لا يشمل الكهرباء.')
        ->and(draftHash())->toBe($oneHash);

    // A later text edit takes the next free version number (versions stay immutable).
    app(SaveDocumentClause::class)->handle($this->f->owner, $clause->refresh(), [
        'key' => 'site', 'title' => ['ar' => 'الموقع'], 'body' => ['ar' => 'نص ثالث.'], 'applies_to' => ['quote'], 'included_by_default' => true,
    ], $clause->lock_version);
    expect($clause->refresh()->version)->toBe(3);

    // The release in force cannot be rolled back to; the audit names the source release.
    expect(fn () => app(PublishConfigRelease::class)->rollback($this->f->owner, $three, null, 3))->toThrow(ValidationException::class)
        ->and(fn () => app(PublishConfigRelease::class)->rollback($this->f->owner, $one, null, 3))->toThrow(ValidationException::class);
    $audit = AuditEvent::query()->where('action', 'config_release.rolled_back')->sole();
    expect($audit->after['release'])->toBe(3)->and($audit->after['restored_from'])->toBe(1);
});

it('discards the draft back to the release in force', function () {
    expect(fn () => app(PublishConfigRelease::class)->discard($this->f->owner, 0))->toThrow(ValidationException::class);
    installmentPlan($this->f->owner);
    $one = publishRules($this->f->owner);

    savePlan($this->f->owner, 'half_half', [planLine('first', 'percent', '50'), planLine('final', 'rest', null)], true);
    app(UpdateReminderSettings::class)->handle($this->f->owner, [...ReminderSettings::DEFAULTS, 'max_after' => 6], null);
    expect(draftHash())->not->toBe($one->snapshot_hash);

    app(PublishConfigRelease::class)->discard($this->f->owner, 1);
    expect(draftHash())->toBe($one->snapshot_hash)
        ->and(ReminderSettings::working()->max_after)->toBe(4)
        ->and(ConfigRelease::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'config_release.draft_discarded')->count())->toBe(1);
});

it('refuses a stale base or a draft changed since it was reviewed (409), and a bundle the published flow cannot use', function () {
    installmentPlan($this->f->owner);
    $hash = draftHash();
    publishRules($this->f->owner);

    // Someone published meanwhile: the base is stale.
    app(UpdateReminderSettings::class)->handle($this->f->owner, [...ReminderSettings::DEFAULTS, 'max_after' => 5], null);
    expect(fn () => app(PublishConfigRelease::class)->publish($this->f->owner, null, 0, draftHash()))->toThrow(StaleRecord::class);
    // The draft changed after the review: its hash no longer matches.
    expect(fn () => app(PublishConfigRelease::class)->publish($this->f->owner, null, 1, $hash))->toThrow(StaleRecord::class);
    $this->actingAs($this->f->owner)->postJson('/settings/releases', ['base' => 0, 'draft_hash' => draftHash()])
        ->assertStatus(409)->assertJsonPath('code', 'stale_version');

    // The published flow waits for the due «first»: a plan without it cannot be released.
    PaymentPlan::query()->where('key', 'forty_forty')->update(['lines' => json_encode([
        ['key' => 'only', 'name' => ['ar' => 'كامل'], 'basis' => 'rest', 'value' => null, 'event' => 'agreement', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0],
    ])]);
    try {
        publishRules($this->f->owner);
        $this->fail('published a plan the flow gates cannot use');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('payment_plans.forty_forty');
    }
    expect(ConfigRelease::query()->count())->toBe(1);
});

it('lets only P-104 holders publish, roll back or discard; P-02 holders read; others see nothing', function () {
    installmentPlan($this->f->owner);
    $hash = draftHash();

    $this->actingAs($this->f->manager)->get('/settings/releases')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('rules/releases')->where('canEdit', false)->where('draft.pending', true)
            ->where('draft.changes.0.section', 'payment_plans')->where('draft.changes.0.change', 'added'));
    $this->actingAs($this->f->manager)->post('/settings/releases', ['base' => 0, 'draft_hash' => $hash])->assertForbidden();
    expect(fn () => app(PublishConfigRelease::class)->publish($this->f->manager, null, 0, $hash))->toThrow(AuthorizationException::class);
    $this->actingAs($this->f->sales)->get('/settings/releases')->assertForbidden();
    $this->actingAs($this->f->sales)->postJson('/settings/releases/preview', ['value' => '1000', 'locale' => 'ar'])->assertForbidden();

    $this->actingAs($this->f->owner)->post('/settings/releases', ['base' => 0, 'draft_hash' => $hash, 'notes' => 'أول إصدار'])->assertRedirect('/settings/releases');
    $one = ConfigRelease::query()->sole();
    $this->actingAs($this->f->manager)->get('/settings/releases/'.$one->id)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('rules/release')->where('release.number', 1)->where('rollback', null));
    $this->actingAs($this->f->manager)->post('/settings/releases/discard', ['base' => 1])->assertForbidden();
    $this->actingAs($this->f->manager)->post('/settings/releases/'.$one->id.'/rollback', ['base' => 1])->assertForbidden();

    // The permission, not the role: a manager granted P-104 publishes.
    MemberGrant::query()->create(['membership_id' => Org::membershipOf($this->f->manager)->id, 'permission' => 'P-104', 'effect' => 'allow', 'scope' => 'tenant']);
    app(UpdateReminderSettings::class)->handle($this->f->manager, [...ReminderSettings::DEFAULTS, 'on_due' => false], null);
    $this->actingAs($this->f->manager)->post('/settings/releases', ['base' => 1, 'draft_hash' => draftHash()])->assertRedirect();
    expect(ConfigRelease::query()->max('number'))->toBe(2)
        ->and(ConfigRelease::query()->where('number', 2)->value('published_by'))->toBe($this->f->manager->id);
});

it('previews the draft against the release in force on sample data without writing anything', function () {
    savePlan($this->f->owner, 'thirds', [planLine('first', 'percent', '33.33'), planLine('mid', 'percent', '33.33'), planLine('final', 'rest', null)], true);
    app(SaveDocumentClause::class)->handle($this->f->owner, null, [
        'key' => 'site', 'title' => ['ar' => 'الموقع'], 'body' => ['ar' => 'السعر {total} للعميل {customer}.'], 'applies_to' => ['quote', 'contract'], 'included_by_default' => true,
    ], null);
    $before = [DB::table('outbox_messages')->count(), ConfigRelease::query()->count(), AuditEvent::query()->count()];

    $response = $this->actingAs($this->f->manager)->postJson('/settings/releases/preview', ['value' => '10000.01', 'locale' => 'ar'])->assertOk();
    expect($response->json('value_minor'))->toBe(1_000_001)
        ->and($response->json('plans.draft.0.key'))->toBe('thirds')
        ->and(array_column($response->json('plans.draft.0.dues'), 'amount_minor'))->toBe([333_300, 333_300, 333_401])
        ->and($response->json('plans.draft.0.total_minor'))->toBe(1_000_001)
        ->and($response->json('plans.published.0.builtin'))->toBeTrue()
        ->and($response->json('clauses.draft.quote.0'))->toContain('37,500.00')->not->toContain('{')
        ->and($response->json('clauses.published.quote'))->toBe([]);
    expect([DB::table('outbox_messages')->count(), ConfigRelease::query()->count(), AuditEvent::query()->count()])->toBe($before);

    $this->actingAs($this->f->manager)->postJson('/settings/releases/preview', ['value' => 'abc', 'locale' => 'ar'])->assertUnprocessable();
});

it('is one facility-wide release for every branch, and the draft banner shows on the rules pages', function () {
    installmentPlan($this->f->owner);
    $one = publishRules($this->f->owner);
    $inB = openCustomOrder($this->f->org->member('sales', [$this->f->org->b]), $this->f->inB);
    $inA = openCustomOrder($this->f->sales, $this->f->inA);
    expect([$inA->config_release_id, $inB->config_release_id])->toBe([$one->id, $one->id])
        ->and(ConfigReleases::forOrder($inB)['payment_plans'][0]['key'])->toBe('forty_forty');

    $this->actingAs($this->f->owner)->get('/settings/reminders')
        ->assertInertia(fn (Assert $p) => $p->where('configDraft.pending', false)->where('configDraft.release', 1));
    app(UpdateReminderSettings::class)->handle($this->f->owner, [...ReminderSettings::DEFAULTS, 'max_after' => 1], null);
    $this->actingAs($this->f->owner)->get('/settings/payment-plans')
        ->assertInertia(fn (Assert $p) => $p->where('configDraft.pending', true));
    $this->actingAs($this->f->owner)->get('/settings/purchase-document')
        ->assertInertia(fn (Assert $p) => $p->where('configDraft.pending', true));
});

it('renders contract clauses only from the order\'s release, in the document language', function () {
    app(SaveDocumentClause::class)->handle($this->f->owner, null, [
        'key' => 'warranty', 'title' => ['ar' => 'الضمان', 'en' => 'Warranty'], 'body' => ['ar' => 'ضمان {order}', 'en' => 'Warranty {order}'],
        'applies_to' => ['contract'], 'included_by_default' => true,
    ], null);
    expect(DocumentClauses::forDocument(DocumentKind::Contract, $this->f->order, [], 'en')['clauses'])->toBe([]);
    publishRules($this->f->owner);
    expect(DocumentClauses::forDocument(DocumentKind::Contract, $this->f->order, [], 'en')['clauses'])->toBe(['Warranty '.$this->f->order->number])
        ->and(DocumentClause::query()->count())->toBe(1);
});
