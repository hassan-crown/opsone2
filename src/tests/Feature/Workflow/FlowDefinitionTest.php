<?php

/**
 * Fixed action catalogue, starting templates, FlowCheck and the facility flow (M-23): one active flow, immutable
 * versions, publishing never changes running orders (WF-01..04, WF-10).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M22Membership\T01DisableMembership;
use App\Actions\StateMachines\M23Flow\T01ChooseTemplate;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Actions\StateMachines\M23Flow\T03PublishFlow;
use App\Actions\StateMachines\M23Flow\T04DiscardDraft;
use App\Enums\FlowStatus;
use App\Enums\StateMachine;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use App\Support\StaleRecord;
use App\Workflow\Catalogue\ActionType;
use App\Workflow\FlowCheck;
use App\Workflow\FlowCheckFailed;
use App\Workflow\FlowProvisioner;
use App\Workflow\FlowTemplates;
use App\Workflow\Transition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Org;

require_once __DIR__.'/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * @return array{stages: list<array<string, mixed>>}
 */
function editedDefault(callable $change): array
{
    $definition = FlowTemplates::definition(FlowTemplates::DEFAULT);
    $change($definition);

    return $definition;
}

/**
 * @return list<string>
 */
function issueCodes(array $definition): array
{
    return array_map(fn (array $i) => $i['code'], app(FlowCheck::class)->run($definition)->issues);
}

it('offers exactly ten fixed system actions in five groups', function () {
    expect(ActionType::cases())->toHaveCount(10)
        ->and(array_unique(array_map(fn (ActionType $t) => $t->group()->value, ActionType::cases())))->toHaveCount(5)
        ->and(ActionType::catalogue()[0])->toHaveKeys(['type', 'group', 'completion', 'label', 'inputs', 'defaults']);
});

it('ships templates that pass the pre-publish check', function (string $template) {
    Org::make();
    $result = app(FlowCheck::class)->run(FlowTemplates::definition($template));

    expect($result->issues)->toBe([])
        ->and($result->definition())->toBe(FlowTemplates::definition($template));
})->with(FlowTemplates::keys());

it('refuses an approval without its document, at any position', function () {
    Org::make();

    // Design approval with no design action at all.
    expect(issueCodes(editedDefault(fn (&$d) => array_splice($d['stages'][1]['actions'], 0, 1))))->toContain('approval_before_document');

    // Quote prepared only in a later stage than its approval.
    $late = editedDefault(function (&$d) {
        $quote = array_pop($d['stages'][1]['actions']);
        $d['stages'][3]['actions'][] = $quote;
    });
    $issues = app(FlowCheck::class)->run($late)->issues;
    expect(array_column($issues, 'code'))->toContain('approval_before_document')
        ->and($issues[0])->toMatchArray(['stage' => 'agreement', 'action' => 'quote_approval', 'path' => 'stages.2.actions.0.type'])
        ->and($issues[0]['message'])->not->toBe('');

    // Same stage is fine: the approval simply waits for the design at run time.
    $same = editedDefault(function (&$d) {
        $d['stages'][1]['actions'] = array_reverse($d['stages'][1]['actions']);
    });
    expect(issueCodes($same))->toBe([]);
});

it('refuses a percentage collection without a prior agreement and a required action waiting on an optional one', function () {
    Org::make();

    $noAgreement = editedDefault(function (&$d) {
        $d['stages'][2]['actions'] = [$d['stages'][2]['actions'][3]];
        $d['stages'][2]['actions'][0]['key'] = 'first_payment';
    });
    expect(issueCodes($noAgreement))->toContain('payment_without_agreement');

    // A fixed amount needs no agreement.
    $fixed = editedDefault(function (&$d) {
        $d['stages'][2]['actions'] = [[...$d['stages'][2]['actions'][3], 'inputs' => ['reference' => 'first', 'basis' => 'amount', 'value' => 500000]]];
    });
    $firstPayment = array_filter(app(FlowCheck::class)->run($fixed)->issues, fn (array $i) => $i['action'] === 'first_payment');
    expect($firstPayment)->toBe([]);

    $optionalDoc = editedDefault(function (&$d) {
        $d['stages'][1]['actions'][2]['requirement'] = 'optional'; // quote optional, its approval stays required
    });
    expect(issueCodes($optionalDoc))->toContain('prerequisite_may_be_skipped');
});

it('reports duplicate purposes, bad references, inactive targets and invalid inputs with their place', function () {
    $org = Org::make();
    $executor = $org->member('executor', [$org->a]);
    app(T01DisableMembership::class)->handle($org->owner, Org::membershipOf($executor), 'left');

    $definition = editedDefault(function (&$d) use ($executor) {
        $d['stages'][4]['actions'][2]['inputs']['reference'] = 'first'; // same as the first payment
        $d['stages'][3]['actions'][2]['after'] = 'installation';        // later stage
        $d['stages'][3]['actions'][1]['assign'] = ['type' => 'member', 'id' => $executor->id];
        $d['stages'][0]['follow_up'] = ['type' => 'department', 'id' => 99999];
        $d['stages'][2]['actions'][3]['inputs']['value'] = 150;          // percent over 100
        $d['stages'][1]['actions'][2]['type'] = 'free_script';
        $d['stages'][0]['actions'][1]['key'] = 'needs';                  // duplicate key
    });

    expect(issueCodes($definition))->toContain(
        'duplicate_reference', 'invalid_after', 'assign_inactive', 'follow_up_inactive', 'invalid_payment_value', 'unknown_action_type', 'duplicate_key',
    );
    expect(issueCodes(['stages' => []]))->toBe(['stages_empty'])
        ->and(issueCodes(['nope' => 1]))->toBe(['invalid_structure']);
});

it('detects actions waiting for each other', function () {
    Org::make();
    $definition = editedDefault(function (&$d) {
        $d['stages'][3]['actions'][1]['after'] = 'quality_check';
    });

    expect(issueCodes($definition))->toContain('dependency_cycle');
});

it('provisions one published default flow and pins it on the first order', function () {
    $f = workflowFixture();
    $flow = Flow::current();

    expect(Flow::query()->count())->toBe(1)
        ->and($flow->status)->toBe(FlowStatus::Published)
        ->and($flow->template_key)->toBe('default')
        ->and(OrderWorkflow::query()->where('order_id', $f->order->id)->value('flow_version_id'))->toBe($flow->published_version_id)
        ->and(app(FlowProvisioner::class)->ensure()->id)->toBe($flow->id)
        ->and(fn () => Flow::query()->create(['status' => 'published', 'template_key' => 'default']))->toThrow(UniqueConstraintViolationException::class);
});

it('M-23: draft, discard, publish and template replacement follow the machine', function () {
    $f = workflowFixture();
    $owner = $f->org->owner;
    $flow = Flow::current();
    $draft = editedDefault(fn (&$d) => $d['stages'][0]['name']['ar'] = 'المعاينة');

    // T03 and T04 are refused from «منشور» with no effect.
    expectRefusedWithoutEffect(fn () => app(T03PublishFlow::class)->handle($owner), condition: 'not_from_published');
    expectRefusedWithoutEffect(fn () => app(T04DiscardDraft::class)->handle($owner), condition: 'not_from_published');

    // T02 → published with draft; saving again stays there.
    $flow = app(T02SaveDraft::class)->handle($owner, $draft, $flow->lock_version);
    expect($flow->status)->toBe(FlowStatus::PublishedWithDraft);
    $flow = app(T02SaveDraft::class)->handle($owner, $draft, $flow->lock_version);

    // T01 is refused while a draft is open, unless the draft is explicitly discarded.
    expectRefusedWithoutEffect(fn () => app(T01ChooseTemplate::class)->handle($owner, 'compact'), condition: 'draft_open');

    // T04 drops the draft; the published version is unchanged.
    $published = $flow->published_version_id;
    $flow = app(T04DiscardDraft::class)->handle($owner, $flow->lock_version);
    expect($flow->status)->toBe(FlowStatus::Published)->and($flow->draft)->toBeNull()->and($flow->published_version_id)->toBe($published);

    // T02 + T03 publish a new immutable version.
    app(T02SaveDraft::class)->handle($owner, $draft);
    $flow = app(T03PublishFlow::class)->handle($owner);
    expect($flow->status)->toBe(FlowStatus::Published)
        ->and($flow->publishedVersion->number)->toBe(2)
        ->and($flow->publishedVersion->definition['stages'][0]['name']['ar'])->toBe('المعاينة')
        ->and(fn () => $flow->publishedVersion->update(['number' => 9]))->toThrow(LogicException::class);

    // T01 with an explicit discard replaces the published flow by a template.
    app(T02SaveDraft::class)->handle($owner, $draft);
    $flow = app(T01ChooseTemplate::class)->handle($owner, 'compact', discardDraft: true);
    expect($flow->status)->toBe(FlowStatus::Published)
        ->and($flow->template_key)->toBe('compact')
        ->and($flow->publishedVersion->number)->toBe(3)
        ->and(DB::table('state_transitions')->where('machine', 'M-23')->orderBy('id')->pluck('transition')->all())
        ->toBe(['T01', 'T02', 'T02', 'T04', 'T02', 'T03', 'T02', 'T04', 'T01']);
});

it('refuses to publish a draft with issues, with structured issues and no side effects', function () {
    $f = workflowFixture();
    $broken = editedDefault(fn (&$d) => array_splice($d['stages'][1]['actions'], 2, 1)); // drop the quote
    app(T02SaveDraft::class)->handle($f->org->owner, $broken);

    $before = workflowSnapshot();
    try {
        app(T03PublishFlow::class)->handle($f->org->owner);
        $this->fail('published');
    } catch (FlowCheckFailed $e) {
        expect(array_column($e->result->issues, 'code'))->toContain('approval_before_document')
            ->and($e->errors())->toHaveKey('stages.2.actions.0.type');
    }
    expect(workflowSnapshot())->toBe($before)
        ->and(Flow::current()->status)->toBe(FlowStatus::PublishedWithDraft);
});

it('never changes running orders when a new version is published; new orders take it', function () {
    $f = workflowFixture();
    $old = $f->order;
    $oldStages = OrderStage::query()->where('order_id', $old->id)->get()->map(fn ($s) => [$s->key, $s->name->get('ar'), $s->status->value])->all();

    app(T01ChooseTemplate::class)->handle($f->org->owner, 'compact');
    $new = openCustomOrder($f->sales, $f->inA);

    expect(OrderStage::query()->where('order_id', $old->id)->get()->map(fn ($s) => [$s->key, $s->name->get('ar'), $s->status->value])->all())->toBe($oldStages)
        ->and(OrderWorkflow::query()->where('order_id', $old->id)->value('flow_version_id'))->toBe(FlowVersion::query()->where('number', 1)->value('id'))
        ->and(OrderStage::query()->where('order_id', $new->id)->orderBy('position')->pluck('key')->all())->toBe(['agreement', 'execution', 'delivery'])
        ->and(OrderWorkflow::query()->where('order_id', $new->id)->value('flow_version_id'))->toBe(Flow::current()->published_version_id);
});

it('lets only P-20 edit and P-21 publish or replace the flow', function () {
    $f = workflowFixture();
    $manager = $f->org->member('manager', [$f->org->a]);
    $supervisor = $f->org->member('supervisor', [$f->org->a], $f->org->d1);

    foreach ([$f->sales, $supervisor] as $user) {
        expectRefusedWithoutEffect(fn () => app(T02SaveDraft::class)->handle($user, FlowTemplates::definition('default')), AuthorizationException::class);
        expectRefusedWithoutEffect(fn () => app(T01ChooseTemplate::class)->handle($user, 'compact'), AuthorizationException::class);
    }

    app(T02SaveDraft::class)->handle($manager, FlowTemplates::definition('default'));
    expectRefusedWithoutEffect(fn () => app(T03PublishFlow::class)->handle($f->sales), AuthorizationException::class);
    expect(app(T03PublishFlow::class)->handle($manager)->status)->toBe(FlowStatus::Published);
});

it('refuses a stale flow version', function () {
    $f = workflowFixture();
    $flow = Flow::current();
    app(T02SaveDraft::class)->handle($f->org->owner, FlowTemplates::definition('default'), $flow->lock_version);

    expectRefusedWithoutEffect(fn () => app(T02SaveDraft::class)->handle($f->org->owner, FlowTemplates::definition('default'), $flow->lock_version), StaleRecord::class);
});

it('rejects an unknown transition code without effect', function () {
    workflowFixture();

    expectRefusedWithoutEffect(fn () => Transition::check(StateMachine::M23, FlowStatus::transitions(), FlowStatus::Published, 'T99'), InvalidTransition::class, 'undefined');
});
