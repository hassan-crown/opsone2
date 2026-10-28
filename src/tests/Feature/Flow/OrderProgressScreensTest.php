<?php

/**
 * Order progress screens over HTTP (M-04, M-06, M-27, P5): reading, pause/resume, completing a stage with open
 * requirements and allowing proceed, rework and editing upcoming work. Refused transitions leave no trace.
 */

use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = workflowFixture();
    $this->order = $this->f->order;
    $this->owner = $this->f->org->owner;
});

function flowUiLock($order): int
{
    return $order->refresh()->lock_version;
}

/** Runs an HTTP call that must be refused and asserts nothing moved. */
function flowUiExpectNoEffect(Closure $call): void
{
    $before = workflowSnapshot();
    $call();
    $after = workflowSnapshot();
    foreach ($before as $table => $rows) {
        expect($after[$table])->toBe($rows, "{$table} changed by a refused call");
    }
}

it('reads the progress for anyone who sees the order, and refuses others', function () {
    $this->actingAs($this->f->sales)->getJson("/orders/{$this->order->id}/progress")->assertOk()
        ->assertJsonPath('progress.flow_version', 1)
        ->assertJsonPath('progress.paused', false)
        ->assertJsonCount(5, 'progress.stages')
        ->assertJsonPath('progress.can.pause', false)
        ->assertJsonPath('progress.can.edit_upcoming', false);
    $this->actingAs($this->owner)->getJson("/orders/{$this->order->id}/progress")->assertOk()
        ->assertJsonPath('progress.can.pause', true)
        ->assertJsonPath('progress.can.edit_upcoming', true)
        ->assertJsonPath('progress.stages.0.status', 'in_progress')
        ->assertJsonPath('progress.stages.0.can_complete', true);

    $outsider = $this->f->org->member('sales', [$this->f->org->c]);
    $this->actingAs($outsider)->getJson("/orders/{$this->order->id}/progress")->assertForbidden();
    $this->actingAs($outsider)->postJson("/orders/{$this->order->id}/pause", ['lock_version' => flowUiLock($this->order), 'reason' => 'x', 'follow_up_user_id' => $outsider->id])->assertForbidden();
});

it('pauses and resumes an order, refusing repeated or unauthorized transitions without side effects', function () {
    $sales = $this->f->sales;
    $url = "/orders/{$this->order->id}";
    $elsewhere = $this->f->org->member('sales', [$this->f->org->c]);

    flowUiExpectNoEffect(function () use ($url, $sales, $elsewhere) {
        $this->actingAs($sales)->postJson("{$url}/pause", ['lock_version' => flowUiLock($this->order), 'reason' => 'سبب', 'follow_up_user_id' => $sales->id])->assertForbidden();
        $this->actingAs($this->owner)->postJson("{$url}/pause", ['lock_version' => flowUiLock($this->order), 'reason' => '', 'follow_up_user_id' => $sales->id])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->owner)->postJson("{$url}/pause", ['lock_version' => flowUiLock($this->order), 'reason' => 'سبب'])->assertUnprocessable()->assertJsonValidationErrors('follow_up_user_id');
        $this->actingAs($this->owner)->postJson("{$url}/pause", ['lock_version' => flowUiLock($this->order), 'reason' => 'سبب', 'follow_up_user_id' => $elsewhere->id])->assertUnprocessable()->assertJsonValidationErrors('follow_up_user_id');
        $this->actingAs($this->owner)->postJson("{$url}/pause", ['lock_version' => flowUiLock($this->order) + 3, 'reason' => 'سبب', 'follow_up_user_id' => $sales->id])->assertStatus(409)->assertJsonPath('code', 'stale_version');
        $this->actingAs($this->owner)->postJson("{$url}/resume", ['lock_version' => flowUiLock($this->order)])->assertStatus(409)->assertJsonPath('condition', 'not_from_in_progress');
    });

    $this->actingAs($this->owner)->postJson("{$url}/pause", [
        'lock_version' => flowUiLock($this->order), 'reason' => 'العميل مسافر', 'follow_up_user_id' => $sales->id, 'review_on' => now()->addWeek()->toDateString(),
    ])->assertOk()
        ->assertJsonPath('progress.paused', true)
        ->assertJsonPath('progress.pause.reason', 'العميل مسافر')
        ->assertJsonPath('progress.pause.follow_up_user_id', $sales->id);
    expect($this->order->refresh()->status)->toBe('paused');

    flowUiExpectNoEffect(function () use ($url, $sales) {
        $this->actingAs($this->owner)->postJson("{$url}/pause", ['lock_version' => flowUiLock($this->order), 'reason' => 'مرة أخرى', 'follow_up_user_id' => $sales->id])->assertStatus(409)->assertJsonPath('condition', 'not_from_paused');
        $this->actingAs($this->owner)->postJson("{$url}/stages/".stageOf($this->order, 'measure')->id.'/complete', ['lock_version' => flowUiLock($this->order)])->assertStatus(409)->assertJsonPath('condition', 'order_paused');
        $this->actingAs($sales)->postJson("{$url}/resume", ['lock_version' => flowUiLock($this->order)])->assertForbidden();
    });

    $this->actingAs($this->owner)->postJson("{$url}/resume", ['lock_version' => flowUiLock($this->order), 'note' => 'عاد العميل'])
        ->assertOk()->assertJsonPath('progress.paused', false)->assertJsonPath('progress.pause', null);
    expect($this->order->refresh()->status)->toBe('in_progress');
});

it('lists open requirements when completing a stage, allows proceeding with a reason, then completes', function () {
    $url = "/orders/{$this->order->id}";
    $stage = stageOf($this->order, 'measure');

    $response = $this->actingAs($this->owner)->postJson("{$url}/stages/{$stage->id}/complete", ['lock_version' => flowUiLock($this->order)])
        ->assertStatus(409)->assertJsonPath('condition', 'requirements_open');
    expect($response->json('open'))->toContain('needs');

    $needs = actionOf($this->order, 'needs');
    $measure = actionOf($this->order, 'measure');
    flowUiExpectNoEffect(function () use ($url, $needs) {
        $this->actingAs($this->f->sales)->postJson("{$url}/actions/{$needs->id}/allow", ['lock_version' => flowUiLock($this->order), 'reason' => 'سماح'])->assertForbidden();
        $this->actingAs($this->owner)->postJson("{$url}/actions/{$needs->id}/allow", ['lock_version' => flowUiLock($this->order), 'reason' => ' '])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson("{$url}/actions/{$needs->id}/allow", ['lock_version' => flowUiLock($this->order) + 2, 'reason' => 'سماح'])->assertStatus(409)->assertJsonPath('code', 'stale_version');
    });

    foreach ([$needs, $measure] as $action) {
        $this->actingAs($this->owner)->postJson("{$url}/actions/{$action->id}/allow", ['lock_version' => flowUiLock($this->order), 'reason' => 'اتفقنا مع العميل'])
            ->assertOk();
    }
    $progress = $this->actingAs($this->owner)->getJson("{$url}/progress")->json('progress');
    expect(collect($progress['stages'][0]['actions'])->pluck('allowed_reason')->filter()->all())->toContain('اتفقنا مع العميل')
        ->and($progress['blockers'])->toBe([]);

    $this->actingAs($this->owner)->postJson("{$url}/stages/{$stage->id}/complete", ['lock_version' => flowUiLock($this->order)])
        ->assertOk()->assertJsonPath('progress.stages.0.status', 'completed');
    expect(OrderWorkflow::query()->where('order_id', $this->order->id)->value('active_stage_id'))->not->toBe($stage->id);

    // A completed stage cannot be completed again.
    flowUiExpectNoEffect(fn () => $this->actingAs($this->owner)->postJson("{$url}/stages/{$stage->id}/complete", ['lock_version' => flowUiLock($this->order)])->assertStatus(409));
});

it('reworks a completed action over HTTP and refuses it where it makes no sense', function () {
    $url = "/orders/{$this->order->id}";
    $needs = actionOf($this->order, 'needs');

    // Not completed yet: refused, nothing moves.
    flowUiExpectNoEffect(function () use ($url, $needs) {
        $this->actingAs($this->owner)->postJson("{$url}/actions/{$needs->id}/rework", ['lock_version' => flowUiLock($this->order), 'reason' => 'خطأ'])->assertStatus(409);
    });

    finishActiveStage($this->owner, $this->order); // measure completed, needs verified
    flowUiExpectNoEffect(function () use ($url, $needs) {
        $this->actingAs($this->f->sales)->postJson("{$url}/actions/{$needs->id}/rework", ['lock_version' => flowUiLock($this->order), 'reason' => 'خطأ'])->assertForbidden();
        $this->actingAs($this->owner)->postJson("{$url}/actions/{$needs->id}/rework", ['lock_version' => flowUiLock($this->order), 'reason' => ''])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson("{$url}/actions/{$needs->id}/rework", ['lock_version' => flowUiLock($this->order) + 1, 'reason' => 'خطأ'])->assertStatus(409)->assertJsonPath('code', 'stale_version');
    });
    $progress = $this->actingAs($this->owner)->getJson("{$url}/progress")->json('progress');
    expect($progress['stages'][0]['actions'][0]['can']['rework'])->toBeTrue();

    $this->actingAs($this->owner)->postJson("{$url}/actions/{$needs->id}/rework", ['lock_version' => flowUiLock($this->order), 'reason' => 'المواصفات ناقصة'])->assertOk();
    expect($needs->refresh()->rework_count)->toBe(1);
});

it('edits only the upcoming work of this order with a reason and surfaces check errors', function () {
    $url = "/orders/{$this->order->id}";
    $get = $this->actingAs($this->owner)->getJson("{$url}/upcoming")->assertOk();
    $keys = collect($get->json('stages'))->pluck('key')->all();
    expect($keys)->toBe(['design', 'agreement', 'manufacture', 'install'])
        ->and($get->json('options.catalogue'))->toHaveCount(10);
    $this->actingAs($this->f->sales)->getJson("{$url}/upcoming")->assertForbidden();

    $stages = $get->json('stages');
    $edited = $stages;
    $edited[2]['actions'][] = ['key' => 'paint_check', 'type' => 'work_task', 'name' => ['ar' => 'فحص الدهان'], 'inputs' => ['label' => ['ar' => 'فحص'], 'requires_attachment' => true]];

    flowUiExpectNoEffect(function () use ($url, $edited, $stages) {
        $this->actingAs($this->f->sales)->putJson("{$url}/upcoming", ['lock_version' => flowUiLock($this->order), 'reason' => 'x', 'stages' => $edited])->assertForbidden();
        $this->actingAs($this->owner)->putJson("{$url}/upcoming", ['lock_version' => flowUiLock($this->order), 'reason' => '', 'stages' => $edited])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->owner)->putJson("{$url}/upcoming", ['lock_version' => flowUiLock($this->order) + 1, 'reason' => 'x', 'stages' => $edited])->assertStatus(409)->assertJsonPath('code', 'stale_version');
        // A stage without actions is a FlowCheck error linked to that stage.
        $broken = $stages;
        $broken[1]['actions'] = [];
        $response = $this->actingAs($this->owner)->putJson("{$url}/upcoming", ['lock_version' => flowUiLock($this->order), 'reason' => 'x', 'stages' => $broken])->assertUnprocessable();
        expect(collect($response->json('issues'))->contains(fn ($i) => $i['code'] === 'stage_without_actions' && $i['stage'] === 'agreement'))->toBeTrue();
    });

    $this->actingAs($this->owner)->putJson("{$url}/upcoming", ['lock_version' => flowUiLock($this->order), 'reason' => 'فحص إضافي لهذا العميل', 'stages' => $edited])->assertOk();
    expect(OrderAction::query()->where('order_id', $this->order->id)->where('key', 'paint_check')->exists())->toBeTrue()
        ->and(OrderStage::query()->where('order_id', $this->order->id)->orderBy('position')->pluck('key')->all())->toBe(['measure', 'design', 'agreement', 'manufacture', 'install'])
        ->and(stageOf($this->order, 'measure')->status->value)->toBe('in_progress');

    // The started stage is no longer offered for editing.
    finishActiveStage($this->owner, $this->order);
    expect(collect($this->actingAs($this->owner)->getJson("{$url}/upcoming")->json('stages'))->pluck('key')->all())->not->toContain('measure');
});

it('renders the order page with the stages tab for a custom order', function () {
    $this->actingAs($this->owner)->get("/orders/{$this->order->id}")->assertOk()->assertInertia(fn ($page) => $page->component('orders/show')->where('order.kind', 'custom'));
});
