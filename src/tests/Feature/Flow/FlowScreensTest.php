<?php

/**
 * Flow settings screens (M-23, P5): page authorization per P-20/P-21, live checks with issues linked to their stage
 * and action, the draft → check → publish flow over HTTP, pinned versions and refusals without side effects.
 */

use App\Enums\FlowStatus;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\OrderWorkflow;
use App\Workflow\FlowTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = workflowFixture();
    $this->owner = $this->f->org->owner;
});

/**
 * @return list<array<string, mixed>>
 */
function flowUiStages(?callable $change = null): array
{
    $definition = FlowTemplates::definition(FlowTemplates::DEFAULT);
    if ($change !== null) {
        $change($definition);
    }

    return $definition['stages'];
}

function flowUiPaintCheck(): array
{
    return ['key' => 'paint_check', 'type' => 'work_task', 'name' => ['ar' => 'فحص الدهان', 'en' => null], 'requirement' => 'optional',
        'applies_when' => 'always', 'assign' => ['type' => 'order_assignee'], 'inputs' => ['label' => ['ar' => 'فحص الدهان'], 'requires_attachment' => false], 'after' => null];
}

function flowUiVersion(): int
{
    return Flow::current()->lock_version;
}

it('shows the editor only to P-20 holders and a stage deep link with the same page', function () {
    $manager = $this->f->org->member('manager');
    foreach ([$this->owner, $manager] as $user) {
        $this->actingAs($user)->get('/settings/flow')->assertOk()->assertInertia(fn ($page) => $page
            ->component('flow/edit')
            ->where('flow.status', 'published')
            ->where('flow.version.number', 1)
            ->has('flow.published.stages', 5)
            ->where('flow.draft', null)
            ->has('options.catalogue', 10)
            ->where('can.publish', true));
    }
    $this->actingAs($this->owner)->get('/settings/flow/stages/design?action=quote')->assertOk()
        ->assertInertia(fn ($page) => $page->where('selected.stage', 'design')->where('selected.action', 'quote'));

    foreach ([$this->f->sales, $this->f->org->member('supervisor', [$this->f->org->a], $this->f->org->d1, 'branches'), $this->f->org->member('executor', [$this->f->org->a], $this->f->org->d1)] as $user) {
        $this->actingAs($user)->get('/settings/flow')->assertForbidden();
    }
});

it('sends a guest to the login page', function () {
    auth()->forgetGuards();
    $this->get('/settings/flow')->assertRedirect('/login');
});

it('offers the flow in the navigation only to those who may edit it', function () {
    $this->actingAs($this->owner)->get('/settings/flow')->assertInertia(fn ($page) => $page->where('orgNav.flow', true));
    $this->actingAs($this->f->sales)->get('/orders')->assertInertia(fn ($page) => $page->where('orgNav.flow', false));
});

it('checks a draft live and links each issue to its stage and action', function () {
    $good = flowUiStages();
    $this->actingAs($this->owner)->postJson('/settings/flow/check', ['stages' => $good])
        ->assertOk()->assertJson(['passes' => true, 'issues' => []]);

    $broken = flowUiStages(function (array &$d) {
        array_shift($d['stages'][1]['actions']); // the design the approval waits for
    });
    $response = $this->actingAs($this->owner)->postJson('/settings/flow/check', ['stages' => $broken])->assertOk()->assertJson(['passes' => false]);
    $issue = collect($response->json('issues'))->firstWhere('code', 'approval_before_document');
    expect($issue)->not->toBeNull()
        ->and($issue['stage'])->toBe('design')
        ->and($issue['action'])->toBe('design_approval')
        ->and($issue['path'])->toBe('stages.1.actions.0.type')
        ->and($issue['message'])->not->toBe('');

    $this->actingAs($this->f->sales)->postJson('/settings/flow/check', ['stages' => $good])->assertForbidden();
    $this->actingAs($this->owner)->postJson('/settings/flow/check', [])->assertUnprocessable();
});

it('saves a draft without touching the published version, then discards it', function () {
    $stages = flowUiStages(fn (array &$d) => $d['stages'][3]['actions'][] = flowUiPaintCheck());

    $this->actingAs($this->owner)->putJson('/settings/flow/draft', ['lock_version' => flowUiVersion(), 'stages' => $stages])
        ->assertOk()
        ->assertJsonPath('flow.status', 'published_with_draft')
        ->assertJsonPath('flow.version.number', 1)
        ->assertJsonCount(4, 'flow.draft.stages.3.actions')
        ->assertJsonCount(3, 'flow.published.stages.3.actions');
    expect(FlowVersion::query()->count())->toBe(1);

    $this->actingAs($this->owner)->getJson('/settings/flow')->assertOk();
    $this->actingAs($this->owner)->get('/settings/flow')->assertInertia(fn ($page) => $page
        ->where('flow.status', 'published_with_draft')->has('flow.draft.stages', 5)->where('flow.draft_by', $this->owner->name));

    $this->actingAs($this->owner)->deleteJson('/settings/flow/draft', ['lock_version' => flowUiVersion()])
        ->assertOk()->assertJsonPath('flow.status', 'published')->assertJsonPath('flow.draft', null);
    expect(Flow::current()->status)->toBe(FlowStatus::Published);
});

it('refuses draft writes without P-20, with a stale version, or with no version, changing nothing', function () {
    $stages = flowUiStages();
    $before = workflowSnapshot();

    $this->actingAs($this->f->sales)->putJson('/settings/flow/draft', ['lock_version' => flowUiVersion(), 'stages' => $stages])->assertForbidden();
    $this->actingAs($this->f->sales)->deleteJson('/settings/flow/draft', ['lock_version' => flowUiVersion()])->assertForbidden();
    $this->actingAs($this->f->sales)->postJson('/settings/flow/publish', ['lock_version' => flowUiVersion()])->assertForbidden();
    $this->actingAs($this->owner)->putJson('/settings/flow/draft', ['lock_version' => flowUiVersion() + 5, 'stages' => $stages])
        ->assertStatus(409)->assertJsonPath('code', 'stale_version');
    $this->actingAs($this->owner)->putJson('/settings/flow/draft', ['stages' => $stages])->assertUnprocessable();
    expect(workflowSnapshot())->toBe($before);
});

it('publishes a checked draft end to end: new orders use it, running orders keep their pinned version', function () {
    $running = $this->f->order;
    $stages = flowUiStages(fn (array &$d) => $d['stages'][3]['actions'][] = flowUiPaintCheck());

    $this->actingAs($this->owner)->putJson('/settings/flow/draft', ['lock_version' => flowUiVersion(), 'stages' => $stages])->assertOk();
    $this->actingAs($this->owner)->postJson('/settings/flow/check', ['stages' => $stages])->assertOk()->assertJson(['passes' => true]);
    $this->actingAs($this->owner)->postJson('/settings/flow/publish', ['lock_version' => flowUiVersion()])
        ->assertOk()
        ->assertJsonPath('flow.status', 'published')
        ->assertJsonPath('flow.version.number', 2)
        ->assertJsonPath('flow.draft', null)
        ->assertJsonCount(4, 'flow.published.stages.3.actions')
        ->assertJsonPath('versions.0.running_orders', 1)
        ->assertJsonPath('versions.1.running_orders', 0);

    // The running order still shows version 1; a new order starts on version 2.
    $this->actingAs($this->owner)->getJson("/orders/{$running->id}/progress")->assertOk()->assertJsonPath('progress.flow_version', 1);
    $newer = openCustomOrder($this->f->sales, $this->f->inA);
    $this->actingAs($this->owner)->getJson("/orders/{$newer->id}/progress")->assertOk()->assertJsonPath('progress.flow_version', 2);
    expect(OrderWorkflow::query()->where('order_id', $running->id)->first()->flowVersion->number)->toBe(1)
        ->and(OrderWorkflow::query()->where('order_id', $newer->id)->first()->flowVersion->number)->toBe(2);

    // The published version is immutable: publishing with no draft is refused.
    $this->actingAs($this->owner)->postJson('/settings/flow/publish', ['lock_version' => flowUiVersion()])->assertStatus(409);
});

it('surfaces FlowCheck issues when publishing a broken draft and keeps the draft and version', function () {
    $broken = flowUiStages(function (array &$d) {
        array_shift($d['stages'][1]['actions']);
    });
    $this->actingAs($this->owner)->putJson('/settings/flow/draft', ['lock_version' => flowUiVersion(), 'stages' => $broken])->assertOk();
    $before = workflowSnapshot();

    $response = $this->actingAs($this->owner)->postJson('/settings/flow/publish', ['lock_version' => flowUiVersion()])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors', 'issues' => [['code', 'path', 'stage', 'action', 'message']]]);
    expect(collect($response->json('issues'))->contains(fn ($i) => $i['stage'] === 'design' && $i['action'] === 'design_approval'))->toBeTrue()
        ->and(array_keys($response->json('errors')))->toContain('stages.1.actions.0.type')
        ->and(workflowSnapshot())->toBe($before)
        ->and(Flow::current()->status)->toBe(FlowStatus::PublishedWithDraft)
        ->and(FlowVersion::query()->count())->toBe(1);
});

it('lets the owner choose a template, asking to discard an open draft first', function () {
    $this->actingAs($this->owner)->putJson('/settings/flow/draft', ['lock_version' => flowUiVersion(), 'stages' => flowUiStages()])->assertOk();
    $before = workflowSnapshot();

    $this->actingAs($this->owner)->postJson('/settings/flow/template', ['lock_version' => flowUiVersion(), 'template' => 'compact'])
        ->assertStatus(409)->assertJsonPath('condition', 'draft_open');
    $this->actingAs($this->owner)->postJson('/settings/flow/template', ['lock_version' => flowUiVersion(), 'template' => 'nope', 'discard_draft' => true])->assertUnprocessable();
    $this->actingAs($this->f->sales)->postJson('/settings/flow/template', ['lock_version' => flowUiVersion(), 'template' => 'compact', 'discard_draft' => true])->assertForbidden();
    expect(workflowSnapshot())->toBe($before);

    $this->actingAs($this->owner)->postJson('/settings/flow/template', ['lock_version' => flowUiVersion(), 'template' => 'compact', 'discard_draft' => true])
        ->assertOk()->assertJsonPath('flow.template_key', 'compact')->assertJsonPath('flow.version.number', 2)->assertJsonPath('flow.draft', null);
    // The running order is unaffected.
    $this->actingAs($this->owner)->getJson("/orders/{$this->f->order->id}/progress")->assertJsonPath('progress.flow_version', 1)->assertJsonCount(5, 'progress.stages');
});

it('shows the template choice in the setup wizard to the owner', function () {
    $this->actingAs($this->owner)->get('/org/setup/flow')->assertOk()->assertInertia(fn ($page) => $page
        ->component('org/setup/flow')
        ->where('flow.template_key', 'default')
        ->has('flowOptions.templates', 2)
        ->where('canChooseFlow', true)
        ->where('canEditFlow', true));
});
