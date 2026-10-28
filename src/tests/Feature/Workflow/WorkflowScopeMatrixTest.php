<?php

/**
 * Scope matrix for the workflow permissions (P-20..P-28, with P-14, P-35, P-54): for each default role, allowed inside
 * its scope, refused outside it, refused without the grant — checked through the central Gate on the real records
 * the Actions authorize (stage for P-25, task for P-22/23/24, action for P-27/P-35, order for P-26/P-28).
 */

use App\Access\Access;
use App\Actions\StateMachines\M07Task\T01AssignTask;
use App\Actions\StateMachines\M07Task\T06ReassignTask;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Actions\StateMachines\M23Flow\T03PublishFlow;
use App\Models\Appointment;
use App\Models\UserNotification;
use App\Notifications\NotificationLinks;
use App\Workflow\FlowTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $f = ordersFixture();
    $org = $f->org;

    // Stage 1 followed by department D1 (serves branch A) and its work routed to D1's inbox.
    $definition = FlowTemplates::definition(FlowTemplates::DEFAULT);
    $definition['stages'][0]['follow_up'] = ['type' => 'department', 'id' => $org->d1->id];
    foreach ($definition['stages'][0]['actions'] as $i => $action) {
        $definition['stages'][0]['actions'][$i]['assign'] = ['type' => 'department', 'id' => $org->d1->id];
    }
    app(T02SaveDraft::class)->handle($org->owner, $definition);
    app(T03PublishFlow::class)->handle($org->owner);

    $this->org = $org;
    $this->roles = [
        'manager_a' => $org->member('manager', [$org->a]),
        'manager_c' => $org->member('manager', [$org->c]),
        'supervisor_d1' => $org->member('supervisor', [$org->a], $org->d1),
        'supervisor_d2' => $org->member('supervisor', [$org->c], $org->d2),
        'sales_a' => $f->sales,
        'executor_d1' => $org->member('executor', [$org->a], $org->d1),
        'finance_a' => $org->member('finance', [$org->a]),
    ];
    $this->inA = openCustomOrder($f->sales, $f->inA);
    $this->inC = openCustomOrder($org->owner, $f->inC);
    $this->task = taskOf($this->inA, 'needs');
    app(T01AssignTask::class)->handle($this->roles['supervisor_d1'], $this->task, $this->roles['executor_d1']->id);
    $this->task->refresh();
    $this->appointment = propose($f->sales, $this->inA, [$this->roles['executor_d1']->id]);
});

/**
 * @param  array<string, bool>  $expected  role => allowed
 */
function assertMatrix(array $roles, string $permission, ?object $record, array $expected): void
{
    foreach ($expected as $role => $allowed) {
        expect(Access::can($roles[$role], $permission, $record))->toBe($allowed, "{$permission} for {$role}");
    }
}

it('P-20 / P-21: facility-wide flow editing and publishing for owner and manager only', function () {
    foreach (['P-20', 'P-21'] as $permission) {
        expect(Access::can($this->org->owner, $permission))->toBeTrue();
        assertMatrix($this->roles, $permission, null, [
            'manager_a' => true, 'manager_c' => true, 'supervisor_d1' => false, 'sales_a' => false, 'executor_d1' => false, 'finance_a' => false,
        ]);
    }
});

it('P-22 / P-23: assignment within branches or the department inbox', function () {
    $inboxA = taskOf($this->inA, 'measure');
    $inboxC = taskOf($this->inC, 'measure'); // D1 routing, but the order is in branch C

    foreach (['P-22', 'P-23'] as $permission) {
        assertMatrix($this->roles, $permission, $inboxA, [
            'manager_a' => true, 'manager_c' => false, 'supervisor_d1' => true, 'supervisor_d2' => false, 'sales_a' => false, 'executor_d1' => false,
        ]);
        assertMatrix($this->roles, $permission, $inboxC, ['manager_a' => false, 'manager_c' => true, 'supervisor_d1' => true, 'supervisor_d2' => false]);
    }
});

it('P-24: the assignee or the authorized inbox/branch; nothing beyond the task', function () {
    assertMatrix($this->roles, 'P-24', $this->task, [
        'executor_d1' => true, 'sales_a' => true, 'finance_a' => true, 'manager_a' => true, 'manager_c' => false, 'supervisor_d1' => true, 'supervisor_d2' => false,
    ]);
    $other = taskOf($this->inA, 'measure');
    assertMatrix($this->roles, 'P-24', $other, ['executor_d1' => false, 'supervisor_d1' => true]);
    // Holding a task never opens the order file (WF-08).
    expect(Access::can($this->roles['executor_d1'], 'P-16', $this->inA))->toBeFalse();
});

it('P-25: stage completion for owner, manager and supervisor in scope (Q-30 a)', function () {
    $stageA = stageOf($this->inA, 'measure');
    $stageC = stageOf($this->inC, 'measure');
    $later = stageOf($this->inA, 'design'); // followed by the order's responsible person, no department

    assertMatrix($this->roles, 'P-25', $stageA, [
        'manager_a' => true, 'manager_c' => false, 'supervisor_d1' => true, 'supervisor_d2' => false, 'sales_a' => false, 'executor_d1' => false, 'finance_a' => false,
    ]);
    assertMatrix($this->roles, 'P-25', $stageC, ['manager_c' => true, 'supervisor_d1' => true, 'supervisor_d2' => false]);
    assertMatrix($this->roles, 'P-25', $later, ['manager_a' => true, 'supervisor_d1' => false]);
});

it('P-26: pause and resume for the order in scope; a supervisor while the active stage is with their department', function () {
    assertMatrix($this->roles, 'P-26', $this->inA, [
        'manager_a' => true, 'manager_c' => false, 'supervisor_d1' => true, 'supervisor_d2' => false, 'sales_a' => false, 'executor_d1' => false,
    ]);

    finishActiveStage($this->org->owner, $this->inA); // the design stage is not with D1
    expect(Access::can($this->roles['supervisor_d1'], 'P-26', $this->inA->refresh()))->toBeFalse()
        ->and(Access::can($this->roles['manager_a'], 'P-26', $this->inA))->toBeTrue();
});

it('P-27 / P-35: rework for owner, manager, supervisor in scope; allowance for owner and manager only', function () {
    $action = actionOf($this->inA, 'measure');

    assertMatrix($this->roles, 'P-27', $action, [
        'manager_a' => true, 'manager_c' => false, 'supervisor_d1' => true, 'supervisor_d2' => false, 'sales_a' => false, 'executor_d1' => false,
    ]);
    assertMatrix($this->roles, 'P-35', $action, [
        'manager_a' => true, 'manager_c' => false, 'supervisor_d1' => false, 'sales_a' => false, 'executor_d1' => false, 'finance_a' => false,
    ]);
    expect(Access::can($this->org->owner, 'P-35', $action))->toBeTrue();
});

it('P-28: upcoming work of an order in scope for owner and manager only', function () {
    assertMatrix($this->roles, 'P-28', $this->inA, [
        'manager_a' => true, 'manager_c' => false, 'supervisor_d1' => false, 'sales_a' => false, 'executor_d1' => false,
    ]);
    assertMatrix($this->roles, 'P-28', $this->inC, ['manager_a' => false, 'manager_c' => true]);
});

it('P-14 / P-54: measurements and appointments within the order or the assigned work', function () {
    $measurementTask = taskOf($this->inA, 'measure');
    app(T01AssignTask::class)->handle($this->roles['supervisor_d1'], $measurementTask, $this->roles['executor_d1']->id);

    assertMatrix($this->roles, 'P-14', $this->inA, ['manager_a' => true, 'sales_a' => true, 'executor_d1' => false, 'manager_c' => false]);
    expect(Access::can($this->roles['executor_d1'], 'P-14', $measurementTask->refresh()))->toBeTrue();

    $probe = new Appointment(['order_id' => $this->inC->id]);
    $probe->setRelation('order', $this->inC);
    assertMatrix($this->roles, 'P-54', $this->appointment, ['sales_a' => true, 'manager_a' => true, 'supervisor_d1' => false, 'executor_d1' => false, 'finance_a' => false]);
    assertMatrix($this->roles, 'P-54', $probe, ['sales_a' => false, 'manager_a' => false, 'manager_c' => true]);
});

it('a task notification link re-checks P-24 on the live task', function () {
    $executor = $this->roles['executor_d1'];
    $notification = UserNotification::query()->where('user_id', $executor->id)->where('type', 'task.assigned')->firstOrFail();
    $links = app(NotificationLinks::class);

    expect($links->resolve($executor, $notification))->toBe(route('orders.show', $this->inA->id));

    app(T06ReassignTask::class)->handle($this->roles['supervisor_d1'], $this->task, $this->roles['supervisor_d1']->id, 'إعادة توزيع');
    expect($links->resolve($executor, $notification))->toBeNull();
});
