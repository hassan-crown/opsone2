<?php

/**
 * ADR-0038 (W13): a stage's responsible person may be the order's owner, a member, a role or a person per branch; an
 * action may send its work to the stage's responsible. Roles and branches resolve to eligible people only (P-24 over
 * the order's branch), the least-loaded role member first, and fall back to the order's owner.
 */

use App\Actions\StateMachines\M23Flow\T01ChooseTemplate;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Actions\StateMachines\M23Flow\T03PublishFlow;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\Flow;
use App\Models\RoleTemplate;
use App\Workflow\FlowCheck;
use App\Workflow\FlowTemplates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/WorkshopTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('attachments'));

/**
 * Publishes the retail flow with the first stage followed by `$followUp` (its actions go to the stage's responsible).
 *
 * @param  array<string, mixed>  $followUp
 */
function publishFirstStageFollowUp(object $f, array $followUp): void
{
    $stages = FlowTemplates::definition(FlowTemplates::RETAIL)['stages'];
    $stages[0]['follow_up'] = $followUp;
    app(T02SaveDraft::class)->handle($f->owner, ['stages' => $stages], Flow::current()->lock_version);
    app(T03PublishFlow::class)->handle($f->owner, Flow::current()->lock_version);
}

function roleId(string $key): int
{
    return (int) RoleTemplate::query()->where('key', $key)->value('id');
}

it('gives the role stage to the least-loaded eligible member of the role and creates the work for them', function () {
    $f = workshopFixture();
    $second = $f->org->member('inventory', [$f->org->a]);
    // The first inventory member already holds open work: the second, free one is chosen.
    taskOf(openCustomOrder($f->sales, $f->customer), 'needs')->forceFill(['assignee_id' => $f->inventory->id, 'status' => TaskStatus::Assigned])->save();
    toWorkshopStage($f);

    $stage = stageOf($f->order, 'workshop');
    expect($stage->follow_up_type)->toBe('role')->and($stage->follow_up_user_id)->toBe($second->id);

    // Branch B's inventory member is never chosen for a branch A order (no P-24 over it).
    expect($stage->follow_up_user_id)->not->toBe($f->inventoryB->id);
});

it('falls back to the order owner when nobody eligible holds the role', function () {
    $f = workshopFixture();
    publishFirstStageFollowUp($f, ['type' => 'role', 'id' => roleId('finance')]);
    $order = openCustomOrder($f->sales, $f->customer);

    expect(stageOf($order, 'measure')->follow_up_user_id)->toBe($f->sales->id)
        ->and(taskOf($order, 'needs')->assignee_id)->toBe($f->sales->id);
});

it('routes a stage per branch and sends the actions\' tasks to that person', function () {
    $f = workshopFixture();
    publishFirstStageFollowUp($f, ['type' => 'per_branch', 'branches' => [
        ['branch_id' => $f->org->a->id, 'user_id' => $f->manager->id],
        ['branch_id' => $f->org->b->id, 'user_id' => $f->inventoryB->id],
    ]]);
    $order = openCustomOrder($f->sales, $f->customer);

    expect(stageOf($order, 'measure')->follow_up_type)->toBe('per_branch')
        ->and(stageOf($order, 'measure')->follow_up_user_id)->toBe($f->manager->id)
        ->and(taskOf($order, 'needs')->assignee_id)->toBe($f->manager->id)
        ->and(taskOf($order, 'needs')->status)->toBe(TaskStatus::Assigned);
});

it('assigns an action\'s work to a role directly', function () {
    $f = workshopFixture();
    $stages = FlowTemplates::definition(FlowTemplates::RETAIL)['stages'];
    $stages[0]['actions'][0]['assign'] = ['type' => 'role', 'id' => roleId('manager')];
    app(T02SaveDraft::class)->handle($f->owner, ['stages' => $stages], Flow::current()->lock_version);
    app(T03PublishFlow::class)->handle($f->owner, Flow::current()->lock_version);

    $order = openCustomOrder($f->sales, $f->customer);
    expect(taskOf($order, 'needs')->assignee_id)->toBe($f->manager->id);
});

it('refuses unknown roles, broken branch rows and «stage» as a stage follow-up', function () {
    $f = workshopFixture();
    $codes = function (callable $change) {
        $d = FlowTemplates::definition(FlowTemplates::RETAIL);
        $change($d);

        return array_column(app(FlowCheck::class)->run($d)->issues, 'code');
    };

    expect($codes(fn (&$d) => $d['stages'][0]['follow_up'] = ['type' => 'role', 'id' => 9999]))->toContain('follow_up_inactive')
        ->and($codes(fn (&$d) => $d['stages'][0]['follow_up'] = ['type' => 'per_branch', 'branches' => []]))->toContain('follow_up_missing')
        ->and($codes(fn (&$d) => $d['stages'][0]['follow_up'] = ['type' => 'per_branch', 'branches' => [
            ['branch_id' => $f->org->a->id, 'user_id' => 999999],
        ]]))->toContain('follow_up_inactive')
        ->and($codes(fn (&$d) => $d['stages'][0]['follow_up'] = ['type' => 'stage']))->toContain('follow_up_missing')
        ->and($codes(fn (&$d) => $d['stages'][0]['actions'][0]['assign'] = ['type' => 'role']))->toContain('assign_inactive');
});

it('lets only flow editors change assignments, and audits the published change', function () {
    $f = workshopFixture();
    $stages = FlowTemplates::definition(FlowTemplates::RETAIL)['stages'];
    $stages[0]['follow_up'] = ['type' => 'role', 'id' => roleId('manager')];

    expectRefusedWithoutEffect(fn () => app(T02SaveDraft::class)->handle($f->sales, ['stages' => $stages], Flow::current()->lock_version), AuthorizationException::class);

    app(T02SaveDraft::class)->handle($f->owner, ['stages' => $stages], Flow::current()->lock_version);
    app(T03PublishFlow::class)->handle($f->owner, Flow::current()->lock_version);
    expect(AuditEvent::query()->where('action', 'flow.published')->exists())->toBeTrue();

    // Choosing a template is audited too (and the industry variants are offered).
    app(T01ChooseTemplate::class)->handle($f->owner, FlowTemplates::RETAIL_ALUMINIUM, true, Flow::current()->lock_version);
    expect(Flow::current()->refresh()->template_key)->toBe(FlowTemplates::RETAIL_ALUMINIUM)
        ->and(AuditEvent::query()->where('action', 'flow.template_chosen')->exists())->toBeTrue();
});
