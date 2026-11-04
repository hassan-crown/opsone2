<?php

use App\Actions\Rules\PublishConfigRelease;
use App\Actions\Rules\SaveAutomationRule;
use App\Automation\AutomationConditions;
use App\Automation\AutomationRules;
use App\Automation\AutomationSimulator;
use App\Configuration\ConfigDiff;
use App\Configuration\ConfigReleases;
use App\Configuration\ConfigSnapshot;
use App\Models\Automation\AutomationEvent;
use App\Models\Automation\AutomationRule;
use App\Models\Automation\AutomationRun;
use App\Models\CustomerMessage;
use App\Models\OrderNote;
use App\Models\OutboxMessage;
use App\Models\Task;
use App\Models\UserNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/AutomationTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

it('accepts only closed triggers, fields of the trigger, operators of the field type and typed values', function () {
    $owner = $this->f->owner;
    $bad = fn (array $over) => fn () => autoRule($owner, 'r'.random_int(1, 99999), $over['trigger'] ?? 'document.approved', $over['actions'] ?? [noteAction()], $over['conditions'] ?? [], $over['params'] ?? []);

    expect($bad(['trigger' => 'order.saved']))->toThrow(ValidationException::class)
        // lead fields do not exist on a document event
        ->and($bad(['conditions' => [['field' => 'lead.channel', 'op' => 'eq', 'value' => 'website']]]))->toThrow(ValidationException::class)
        // «contains» is not an operator, and a money field takes integers
        ->and($bad(['conditions' => [['field' => 'order.total', 'op' => 'contains', 'value' => 1]]]))->toThrow(ValidationException::class)
        ->and($bad(['conditions' => [['field' => 'order.total', 'op' => 'gt', 'value' => 'DROP TABLE']]]))->toThrow(ValidationException::class)
        ->and($bad(['conditions' => [['field' => 'document.kind', 'op' => 'eq', 'value' => 'invoice']]]))->toThrow(ValidationException::class)
        // unknown placeholder, unknown action, unknown role, timer parameter out of range, too many actions
        ->and($bad(['actions' => [noteAction('{password}')]]))->toThrow(ValidationException::class)
        ->and($bad(['actions' => [['type' => 'record_payment']]]))->toThrow(ValidationException::class)
        ->and($bad(['actions' => [['type' => 'notify', 'to' => ['type' => 'role', 'role' => 'nobody'], 'message' => ['ar' => 'x']]]]))->toThrow(ValidationException::class)
        ->and($bad(['trigger' => 'payment.due_soon', 'params' => ['days' => 99]]))->toThrow(ValidationException::class)
        ->and($bad(['actions' => array_fill(0, 6, noteAction())]))->toThrow(ValidationException::class);

    $rule = autoRule($owner, 'ok', 'document.approved', [noteAction()], [
        ['field' => 'order.total', 'op' => 'between', 'value' => ['100', 900000]],
        ['field' => 'customer.kind', 'op' => 'in', 'value' => ['person', 'organization', 'person']],
    ], match: 'any');
    expect($rule->conditions)->toBe(['match' => 'any', 'items' => [
        ['field' => 'order.total', 'op' => 'between', 'value' => [100, 900000]],
        ['field' => 'customer.kind', 'op' => 'in', 'value' => ['person', 'organization']],
    ]]);
});

it('compares facts without executing anything: any/all, missing facts and booleans', function () {
    $c = fn (string $match, array $items) => AutomationConditions::evaluate(['match' => $match, 'items' => $items], ['order.total' => 500, 'customer.has_email' => false, 'branch' => 3])['passed'];

    expect($c('all', []))->toBeTrue()
        ->and($c('all', [['field' => 'order.total', 'op' => 'gte', 'value' => 500], ['field' => 'branch', 'op' => 'in', 'value' => ['3', '4']]]))->toBeTrue()
        ->and($c('all', [['field' => 'order.total', 'op' => 'gt', 'value' => 500], ['field' => 'branch', 'op' => 'eq', 'value' => '3']]))->toBeFalse()
        ->and($c('any', [['field' => 'order.total', 'op' => 'gt', 'value' => 500], ['field' => 'branch', 'op' => 'eq', 'value' => '3']]))->toBeTrue()
        ->and($c('all', [['field' => 'customer.has_email', 'op' => 'is_false', 'value' => null]]))->toBeTrue()
        // A fact the subject lacks never satisfies a comparison.
        ->and($c('all', [['field' => 'due.amount', 'op' => 'lt', 'value' => 1000]]))->toBeFalse();
});

it('tries a draft rule on recent real events and sends or writes nothing', function () {
    finAgree($this->f, '10000');
    $before = [OutboxMessage::query()->count(), CustomerMessage::query()->count(), Task::query()->count(), UserNotification::query()->count(), OrderNote::query()->count()];

    $rule = AutomationRules::normalize([
        'key' => 'try_me', 'name' => ['ar' => 'تجربة'], 'trigger' => 'document.approved', 'enabled' => true,
        'conditions' => ['match' => 'all', 'items' => [['field' => 'document.kind', 'op' => 'eq', 'value' => 'quote']]],
        'actions' => [
            ['type' => 'send_message', 'template' => 'quote_ready', 'channel' => 'auto'],
            ['type' => 'create_task', 'title' => ['ar' => 'اتصل بـ {customer}'], 'sla_days' => 1, 'to' => ['type' => 'responsible']],
            ['type' => 'notify', 'to' => ['type' => 'role', 'role' => 'manager'], 'message' => ['ar' => 'اعتماد {order}']],
            noteAction(),
        ],
    ]);
    $samples = app(AutomationSimulator::class)->run($rule);

    expect($samples)->toHaveCount(1)
        ->and($samples[0]['outcome'])->toBe('matched')
        ->and(array_column($samples[0]['actions'], 'would'))->toBe([true, true, true, true])
        ->and($samples[0]['actions'][0]['summary']['text'])->toContain($this->f->order->number)
        ->and($samples[0]['actions'][2]['summary']['to'])->toContain($this->f->manager->name)
        ->and([OutboxMessage::query()->count(), CustomerMessage::query()->count(), Task::query()->count(), UserNotification::query()->count(), OrderNote::query()->count()])->toBe($before)
        ->and(AutomationEvent::query()->count())->toBe(0)
        ->and(AutomationRun::query()->count())->toBe(0);
});

it('makes rules part of the configuration release: change log, check, rollback archives and restores', function () {
    autoRule($this->f->owner, 'first', 'document.approved', [noteAction()]);
    $one = publishRules($this->f->owner);
    expect($one->snapshot['automation'][0]['key'])->toBe('first')
        ->and($one->automation_triggers)->toBe(['document.approved']);

    autoRule($this->f->owner, 'second', 'lead.created', [noteAction()]);
    app(SaveAutomationRule::class)->archive($this->f->owner, AutomationRule::query()->where('key', 'first')->sole());
    $changes = ConfigDiff::between(ConfigReleases::current(), ConfigSnapshot::capture());
    expect(collect($changes)->where('section', 'automation')->map(fn ($c) => $c['item'].':'.$c['change'])->values()->all())->toBe(['second:added', 'first:removed']);
    $two = publishRules($this->f->owner);

    app(PublishConfigRelease::class)->rollback($this->f->owner, $one, 'رجوع', $two->number);
    expect(AutomationRule::query()->active()->pluck('key')->all())->toBe(['first'])
        ->and(AutomationRule::query()->where('key', 'second')->value('archived_at'))->not->toBeNull()
        ->and(ConfigReleases::current()['automation'][0]['key'])->toBe('first');
});

it('refuses to publish a rule whose member left: the release check re-validates the automation section', function () {
    $member = $this->f->org->member('sales', [$this->f->org->a]);
    autoRule($this->f->owner, 'to_member', 'document.approved', [['type' => 'notify', 'to' => ['type' => 'member', 'id' => $member->id], 'message' => ['ar' => 'x']]]);
    Org::membershipOf($member)->forceFill(['status' => 'disabled', 'disabled_at' => now()])->save();

    expect(fn () => publishRules($this->f->owner))->toThrow(ValidationException::class);
});

it('lets only P-104 holders change, kill or publish rules; P-02 holders read; others get 403', function () {
    $rule = autoRule($this->f->owner, 'guarded', 'document.approved', [noteAction()]);
    $payload = ['key' => 'x1', 'name' => ['ar' => 'س'], 'trigger' => 'document.approved', 'actions' => [noteAction()], 'enabled' => true];

    $this->actingAs($this->f->sales)->get('/settings/automation')->assertForbidden();
    $this->actingAs($this->f->sales)->post('/settings/automation', $payload)->assertForbidden();
    $this->actingAs($this->f->sales)->post("/settings/automation/{$rule->id}/kill", ['reason' => 'x'])->assertForbidden();
    expect(fn () => app(SaveAutomationRule::class)->kill($this->f->manager, $rule, 'x'))->toThrow(AuthorizationException::class);

    $this->actingAs($this->f->manager)->get('/settings/automation')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rules/automation')->where('canEdit', false)->has('rules', 1)->has('templates', 5));
    $this->actingAs($this->f->manager)->post('/settings/automation', $payload)->assertForbidden();

    $this->actingAs($this->f->owner)->post('/settings/automation', $payload)->assertRedirect();
    $this->actingAs($this->f->owner)->post("/settings/automation/{$rule->id}/kill", ['reason' => 'توقف'])->assertRedirect();
    expect(AutomationRule::query()->where('key', 'x1')->exists())->toBeTrue()->and($rule->refresh()->killed_at)->not->toBeNull();
});

it('serves the builder with the catalogue and a template, tries over HTTP and shows the run log', function () {
    $this->actingAs($this->f->owner)->get('/settings/automation/new?template=payment_reminder_3d')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rules/automation-builder')
            ->where('draft.trigger', 'payment.due_soon')->where('draft.params.days', 3)
            ->has('catalogue.triggers', 19)->has('catalogue.fields')->has('catalogue.roles'));

    autoRule($this->f->owner, 'logged', 'document.approved', [noteAction()]);
    publishRules($this->f->owner);
    finAgree($this->f, '10000');

    $this->actingAs($this->f->owner)->postJson('/settings/automation/try', [
        'key' => 'logged', 'name' => ['ar' => 'س'], 'trigger' => 'document.approved', 'actions' => [noteAction()], 'enabled' => true,
    ])->assertOk()->assertJsonPath('samples.0.outcome', 'matched')->assertJsonPath('samples.0.actions.0.would', true);
    $this->actingAs($this->f->owner)->get('/settings/automation/log/logged')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rules/automation-log')->has('runs', 1)->where('runs.0.outcome', 'matched'));
});

it('keeps one deployment per facility: no tenant column, isolation is the instance (ADR-0002)', function () {
    foreach (['automation_rules', 'automation_events', 'automation_runs', 'automation_actions', 'order_notes'] as $table) {
        expect(Schema::hasColumn($table, 'tenant_id'))->toBeFalse();
    }
});
