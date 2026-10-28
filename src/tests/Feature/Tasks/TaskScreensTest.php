<?php

/**
 * P5 task screens (M-07, SHR-02, WF-07..09): HTTP authorization per P-22..P-28 (in scope, out of scope, unauthorized),
 * the inbox claim race, measurement entry by the executor, and home cards that respect scope.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M07Task\T01AssignTask;
use App\Actions\StateMachines\M07Task\T10ClaimTask;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Actions\StateMachines\M23Flow\T03PublishFlow;
use App\Enums\TaskStatus;
use App\Models\Order;
use App\Models\OrderMeasurement;
use App\Models\Task;
use App\Workflow\FlowTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $f = ordersFixture();
    $org = $f->org;

    $definition = FlowTemplates::definition(FlowTemplates::DEFAULT);
    $definition['stages'][0]['follow_up'] = ['type' => 'department', 'id' => $org->d1->id];
    foreach ($definition['stages'][0]['actions'] as $i => $action) {
        $definition['stages'][0]['actions'][$i]['assign'] = ['type' => 'department', 'id' => $org->d1->id];
    }
    app(T02SaveDraft::class)->handle($org->owner, $definition);
    app(T03PublishFlow::class)->handle($org->owner);

    $this->org = $org;
    $this->supervisor = $org->member('supervisor', [$org->a], $org->d1);
    $this->exec1 = $org->member('executor', [$org->a], $org->d1);
    $this->exec2 = $org->member('executor', [$org->a], $org->d1);
    $this->sales1 = $org->member('sales', [$org->a], $org->d1);
    $this->sales2 = $org->member('sales', [$org->a], $org->d1);
    $this->execC = $org->member('executor', [$org->c], $org->d2);
    $this->supervisorC = $org->member('supervisor', [$org->c], $org->d2);
    $this->nobody = $org->member(null);
    $this->inA = openCustomOrder($f->sales, $f->inA);
    $this->inC = openCustomOrder($org->owner, $f->inC);
    $this->needsA = taskOf($this->inA, 'needs');
    $this->measureA = taskOf($this->inA, 'measure');
    $this->needsC = taskOf($this->inC, 'needs');
    // Unassigned open work of an order, to compare with what a scoped list may show.
    $this->unassigned = fn (Order $order): int => Task::query()->where('order_id', $order->id)->where('status', TaskStatus::Unassigned->value)->count();
});

it('lists the inbox and my work only inside the user scope, and refuses users without a grant', function () {
    app(T01AssignTask::class)->handle($this->supervisor, $this->needsA, $this->exec1->id);

    // The executor holds only what is assigned to them; the inbox belongs to members authorized over it.
    $this->actingAs($this->exec1)->getJson('/tasks')->assertOk()
        ->assertJsonPath('counts.mine', 1)->assertJsonPath('counts.inbox', 0)->assertJsonCount(1, 'tasks');

    $this->actingAs($this->sales1)->getJson('/tasks?view=inbox')->assertOk()
        ->assertJsonPath('counts.inbox', ($this->unassigned)($this->inA))->assertJsonMissing(['number' => $this->inC->number]);

    // The work is routed to department D1: a supervisor of another department sees none of it.
    $this->actingAs($this->supervisorC)->getJson('/tasks?view=inbox')->assertOk()->assertJsonCount(0, 'tasks')
        ->assertJsonMissing(['number' => $this->inA->number])->assertJsonMissing(['number' => $this->inC->number]);

    $this->actingAs($this->nobody)->getJson('/tasks')->assertForbidden();
    $this->actingAs($this->exec1)->get('/tasks')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('tasks/index')->where('view', 'all'));
});

it('shows the task page to the holder without money, and refuses out-of-scope and unauthorized users', function () {
    app(T01AssignTask::class)->handle($this->supervisor, $this->needsA, $this->exec1->id);

    $response = $this->actingAs($this->exec1)->getJson("/tasks/{$this->needsA->id}")->assertOk()
        ->assertJsonPath('task.status', 'assigned')->assertJsonPath('task.can.start', true)->assertJsonPath('task.can.cancel', false);
    expect(json_encode($response->json()))->not->toContain('price_minor')->not->toContain('total_minor');

    $this->actingAs($this->execC)->getJson("/tasks/{$this->needsA->id}")->assertForbidden();
    $this->actingAs($this->nobody)->getJson("/tasks/{$this->needsA->id}")->assertForbidden();
});

it('assigns inside scope only: supervisor yes, executor and out-of-scope supervisor no, ineligible member refused', function () {
    $url = "/tasks/{$this->needsA->id}/assign";

    $this->actingAs($this->exec1)->postJson($url, ['assignee_id' => $this->exec2->id])->assertForbidden();
    $this->actingAs($this->supervisorC)->postJson($url, ['assignee_id' => $this->exec2->id])->assertForbidden();
    $this->actingAs($this->supervisorC)->get("{$url}")->assertForbidden();
    $this->actingAs($this->supervisor)->postJson($url, ['assignee_id' => $this->execC->id])->assertStatus(422);
    $this->actingAs($this->supervisor)->postJson($url, [])->assertStatus(422);

    $this->actingAs($this->supervisor)->get($url)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('tasks/assign')->where('mode', 'assign')
            ->where('eligible', fn ($e) => collect($e)->pluck('user_id')->contains($this->exec2->id) && ! collect($e)->pluck('user_id')->contains($this->execC->id)));

    $this->actingAs($this->supervisor)->postJson($url, ['assignee_id' => $this->exec2->id])->assertOk()
        ->assertJsonPath('task.assignee.id', $this->exec2->id)->assertJsonPath('task.status', 'assigned');

    // Reassignment (P-23) needs a reason and the same scope.
    $re = "/tasks/{$this->needsA->id}/reassign";
    $this->actingAs($this->supervisor)->postJson($re, ['assignee_id' => $this->exec1->id])->assertStatus(422);
    $this->actingAs($this->exec2)->postJson($re, ['assignee_id' => $this->exec1->id, 'reason' => 'x'])->assertForbidden();
    $this->actingAs($this->supervisor)->postJson($re, ['assignee_id' => $this->exec1->id, 'reason' => 'إجازة'])->assertOk()
        ->assertJsonPath('task.assignee.id', $this->exec1->id);
});

it('lets exactly one of two members win a claim from the inbox', function () {
    $url = "/tasks/{$this->needsA->id}/claim";

    $this->actingAs($this->execC)->postJson($url)->assertForbidden();
    $this->actingAs($this->exec1)->postJson($url)->assertForbidden();
    $this->actingAs($this->sales1)->postJson($url)->assertOk()->assertJsonPath('task.assignee.id', $this->sales1->id);
    $this->actingAs($this->sales2)->postJson($url)->assertStatus(409)->assertJsonPath('condition', 'not_from_assigned');

    $fresh = $this->needsA->refresh();
    expect($fresh->assignee_id)->toBe($this->sales1->id)->and($fresh->status)->toBe(TaskStatus::Assigned)
        ->and(Task::query()->whereKey($fresh->id)->where('assignee_id', $this->sales2->id)->exists())->toBeFalse();

    // The loser's second attempt through the Action sees the same current result.
    expect(fn () => app(T10ClaimTask::class)->handle($this->sales2, $this->needsA->refresh()))->toThrow(InvalidTransition::class);
});

it('walks start → wait → resolve → complete over HTTP and reports stale versions', function () {
    app(T01AssignTask::class)->handle($this->supervisor, $this->needsA, $this->exec1->id);
    $base = "/tasks/{$this->needsA->id}";

    $this->actingAs($this->exec2)->postJson("$base/start")->assertForbidden();
    $this->actingAs($this->exec1)->postJson("$base/start", ['lock_version' => 99])->assertStatus(409)->assertJsonPath('code', 'stale_version');
    $this->actingAs($this->exec1)->postJson("$base/start")->assertOk()->assertJsonPath('task.status', 'in_progress');
    $this->actingAs($this->exec1)->postJson("$base/wait", ['need' => '', 'follow_up_on' => now()->addDay()->toDateString()])->assertStatus(422);
    $this->actingAs($this->exec1)->postJson("$base/wait", ['need' => 'صور الموقع', 'follow_up_on' => now()->addDays(2)->toDateString()])->assertOk()
        ->assertJsonPath('task.status', 'waiting')->assertJsonPath('task.waiting_need', 'صور الموقع');
    $this->actingAs($this->exec1)->postJson("$base/resolve")->assertOk()->assertJsonPath('task.status', 'in_progress');
    $this->actingAs($this->exec1)->postJson("$base/complete", ['result_note' => 'تم'])->assertOk()->assertJsonPath('task.status', 'completed');
});

it('validates measurement entry and lets only the assigned executor record through the task', function () {
    app(T01AssignTask::class)->handle($this->supervisor, $this->measureA, $this->exec1->id);
    $url = "/tasks/{$this->measureA->id}/measurements";
    $valid = ['location' => 'جدار المطبخ', 'name' => 'العرض', 'value' => '3.25', 'unit' => 'm', 'kind' => 'final', 'measured_on' => now()->toDateString()];

    $this->actingAs($this->exec1)->postJson($url, [...$valid, 'value' => '-1'])->assertStatus(422)->assertJsonValidationErrors('value');
    $this->actingAs($this->exec1)->postJson($url, [...$valid, 'value' => '1.2345'])->assertStatus(422)->assertJsonValidationErrors('value');
    $this->actingAs($this->exec1)->postJson($url, [...$valid, 'unit' => 'km'])->assertStatus(422)->assertJsonValidationErrors('unit');
    $this->actingAs($this->exec1)->postJson($url, [...$valid, 'measured_on' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('measured_on');
    $this->actingAs($this->exec1)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['location', 'name', 'value', 'unit', 'kind', 'measured_on']);
    expect(OrderMeasurement::query()->count())->toBe(0);

    $this->actingAs($this->exec2)->postJson($url, $valid)->assertForbidden();
    $this->actingAs($this->execC)->postJson($url, $valid)->assertForbidden();

    $this->actingAs($this->exec1)->get("/tasks/{$this->measureA->id}/measure")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('tasks/measure')->where('kind', 'final'));
    $this->actingAs($this->exec2)->get("/tasks/{$this->measureA->id}/measure")->assertForbidden();

    $this->actingAs($this->exec1)->postJson($url, $valid)->assertCreated()->assertJsonPath('measurements.0.value', '3.25');
    $id = OrderMeasurement::query()->firstOrFail()->id;
    $this->actingAs($this->exec1)->putJson("$url/$id", [...$valid, 'value' => '3.3'])->assertOk()->assertJsonPath('measurements.0.value', '3.3');

    // A measurement of another order is never reachable through this task.
    $other = OrderMeasurement::query()->create([...collect($valid)->except('measured_on')->all(), 'measured_on' => now()->toDateString(), 'order_id' => $this->inC->id, 'value' => 1]);
    $this->actingAs($this->exec1)->putJson("$url/{$other->id}", $valid)->assertNotFound();

    // Completion now succeeds because a final measurement exists.
    $this->actingAs($this->exec1)->postJson("/tasks/{$this->measureA->id}/start")->assertOk();
    $this->actingAs($this->exec1)->postJson("/tasks/{$this->measureA->id}/complete")->assertOk()->assertJsonPath('task.status', 'completed');
});

it('refuses completion without the measurement and shows the attachment requirement', function () {
    app(T01AssignTask::class)->handle($this->supervisor, $this->measureA, $this->exec1->id);

    $this->actingAs($this->exec1)->postJson("/tasks/{$this->measureA->id}/complete")->assertStatus(422)->assertJsonValidationErrors('measurements');
    $this->actingAs($this->exec1)->getJson("/tasks/{$this->measureA->id}")->assertOk()
        ->assertJsonPath('task.action.completion', 'measurement')->assertJsonPath('task.can.measure', true);
});

it('fills the home cards with counts that respect scope', function () {
    app(T01AssignTask::class)->handle($this->supervisor, $this->needsA, $this->exec1->id);

    $this->actingAs($this->exec1)->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('dashboard')
            ->where('taskCards.my_tasks.count', 1)->where('taskCards.my_tasks.inbox', 0)
            ->where('isExecutor', true)->where('exec.today', fn ($t) => count($t) >= 0)
            ->missing('taskCards.department_inbox'));

    // The supervisor of D1 sees the unassigned work routed to D1 (department scope, both branches' orders).
    $this->actingAs($this->supervisor)->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('taskCards.department_inbox.count', ($this->unassigned)($this->inA) + ($this->unassigned)($this->inC))->where('isExecutor', false));

    $this->actingAs($this->supervisorC)->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('taskCards.department_inbox.count', 0)->where('taskCards.my_tasks.count', 0));
});
