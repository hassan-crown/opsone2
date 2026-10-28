<?php

/**
 * Tasks (M-07, WF-04, WF-07..09, SHR-02): routing, assignment to eligible members, department inbox and claim, start,
 * waiting, completion, reassignment, cancellation and the release of a disabled member's work.
 */

use App\Access\Access;
use App\Actions\StateMachines\M07Task\T01AssignTask;
use App\Actions\StateMachines\M07Task\T02StartTask;
use App\Actions\StateMachines\M07Task\T03WaitForNeed;
use App\Actions\StateMachines\M07Task\T04ResolveNeed;
use App\Actions\StateMachines\M07Task\T05CompleteTask;
use App\Actions\StateMachines\M07Task\T06ReassignTask;
use App\Actions\StateMachines\M07Task\T07CancelTask;
use App\Actions\StateMachines\M07Task\T10ClaimTask;
use App\Actions\StateMachines\M22Membership\T01DisableMembership;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Actions\StateMachines\M23Flow\T03PublishFlow;
use App\Actions\Workflow\EditUpcomingWork;
use App\Enums\ActionStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Models\Attachment;
use App\Models\StateTransition;
use App\Models\Task;
use App\Models\UserNotification;
use App\Workflow\FlowTemplates;
use App\Workflow\OrderDefinition;
use App\Workflow\Transition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Org;

require_once __DIR__.'/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Publishes the default flow with stage 1's actions routed to department D1's inbox and opens an order in branch A.
 */
function inboxFixture(object $f): object
{
    $definition = FlowTemplates::definition(FlowTemplates::DEFAULT);
    foreach ($definition['stages'][0]['actions'] as $i => $action) {
        $definition['stages'][0]['actions'][$i]['assign'] = ['type' => 'department', 'id' => $f->org->d1->id];
    }
    $definition['stages'][0]['follow_up'] = ['type' => 'department', 'id' => $f->org->d1->id];
    app(T02SaveDraft::class)->handle($f->org->owner, $definition);
    app(T03PublishFlow::class)->handle($f->org->owner);

    $f->supervisor = $f->org->member('supervisor', [$f->org->a], $f->org->d1);
    $f->executor = $f->org->member('executor', [$f->org->a], $f->org->d1);
    $f->inbox = openCustomOrder($f->sales, $f->inA);

    return $f;
}

beforeEach(function () {
    $this->f = workflowFixture();
    $this->owner = $this->f->org->owner;
    $this->task = taskOf($this->f->order, 'needs');
});

it('walks M-07 T02 → T03 → T04 → T05 with the right preconditions', function () {
    $sales = $this->f->sales;

    // T03 needs a need and a follow-up date that is not past.
    expect(fn () => app(T03WaitForNeed::class)->handle($sales, $this->task, '', now()->addDay()))->toThrow(ValidationException::class)
        ->and(fn () => app(T03WaitForNeed::class)->handle($sales, $this->task, 'صور', now()->subDays(2)))->toThrow(ValidationException::class);
    expectRefusedWithoutEffect(fn () => app(T04ResolveNeed::class)->handle($sales, $this->task), condition: 'not_from_assigned');

    app(T02StartTask::class)->handle($sales, $this->task);
    expect($this->task->refresh()->status)->toBe(TaskStatus::InProgress);
    expectRefusedWithoutEffect(fn () => app(T02StartTask::class)->handle($sales, $this->task), condition: 'not_from_in_progress');

    app(T03WaitForNeed::class)->handle($sales, $this->task, 'بانتظار صور المطبخ من العميل', now()->addDays(2));
    expect($this->task->refresh()->status)->toBe(TaskStatus::Waiting)->and($this->task->waiting_need)->toBe('بانتظار صور المطبخ من العميل');
    expectRefusedWithoutEffect(fn () => app(T05CompleteTask::class)->handle($sales, $this->task), condition: 'not_from_waiting');

    app(T04ResolveNeed::class)->handle($sales, $this->task, 'وصلت الصور');
    app(T05CompleteTask::class)->handle($sales, $this->task, 'تم تثبيت الاحتياج');
    expect($this->task->refresh()->status)->toBe(TaskStatus::Completed)
        ->and($this->task->completed_by)->toBe($sales->id)
        ->and(statusOf(actionOf($this->f->order, 'needs')))->toBe(ActionStatus::Verified);

    foreach (['T02', 'T03', 'T05', 'T06', 'T07'] as $code) {
        expect(TaskStatus::transitions()[$code]['from'])->not->toContain(TaskStatus::Completed);
    }
    expectRefusedWithoutEffect(fn () => app(T07CancelTask::class)->handle($this->owner, $this->task, 'x'), condition: 'not_from_completed');
    expect(StateTransition::query()->where('machine', 'M-07')->where('subject_id', $this->task->id)->orderBy('id')->pluck('transition')->all())
        ->toBe(['T00', 'T02', 'T03', 'T04', 'T05']);
});

it('M-07-T05 needs the attachment its action requires', function () {
    $f = $this->f;
    finishActiveStage($this->owner, $f->order); // measure
    $definition = OrderDefinition::fixedStages($f->order);
    $upcoming = array_slice($definition, 2);
    $upcoming[1]['actions'][1]['inputs']['requires_attachment'] = true; // manufacturing
    app(EditUpcomingWork::class)->handle($this->owner, $f->order, $upcoming, 'صور إلزامية', $f->order->refresh()->lock_version);
    foreach (range(1, 2) as $i) {
        finishActiveStage($this->owner, $f->order);
    }
    $task = taskOf($f->order, 'manufacturing');

    expect(fn () => app(T05CompleteTask::class)->handle($f->sales, $task))->toThrow(ValidationException::class);
    Attachment::query()->create([
        'uuid' => (string) Str::uuid(), 'attachable_type' => $task->getMorphClass(), 'attachable_id' => $task->id,
        'disk' => 'local', 'path' => 'x/'.uniqid(), 'original_name' => 'p.jpg', 'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10,
        'sha256' => str_repeat('a', 64),
    ]);
    app(T05CompleteTask::class)->handle($f->sales, $task);
    expect($task->refresh()->status)->toBe(TaskStatus::Completed);
});

it('M-07-T06: reassigns with a reason to an eligible member only', function () {
    $salesA2 = $this->f->org->member('sales', [$this->f->org->a]);
    $salesC = $this->f->org->member('sales', [$this->f->org->c]);

    expect(fn () => app(T06ReassignTask::class)->handle($this->owner, $this->task, $salesA2->id, ''))->toThrow(ValidationException::class);
    expect(fn () => app(T06ReassignTask::class)->handle($this->owner, $this->task, $salesC->id, 'x'))->toThrow(ValidationException::class);
    expect(fn () => app(T06ReassignTask::class)->handle($this->owner, $this->task, $this->f->sales->id, 'x'))->toThrow(ValidationException::class);
    expectRefusedWithoutEffect(fn () => app(T06ReassignTask::class)->handle($this->f->sales, $this->task, $salesA2->id, 'x'), AuthorizationException::class);

    app(T06ReassignTask::class)->handle($this->owner, $this->task, $salesA2->id, 'ضغط عمل');
    $t = StateTransition::query()->where('machine', 'M-07')->where('transition', 'T06')->firstOrFail();
    expect($this->task->refresh()->assignee_id)->toBe($salesA2->id)
        ->and($this->task->status)->toBe(TaskStatus::Assigned)
        ->and($t->reason)->toBe('ضغط عمل')
        ->and($t->context)->toMatchArray(['previous_assignee_id' => $this->f->sales->id, 'assignee_id' => $salesA2->id])
        ->and(UserNotification::query()->where('user_id', $salesA2->id)->where('type', 'task.assigned')->exists())->toBeTrue();
});

it('M-07-T07: cancels optional work with a reason; required work waits for its stage condition', function () {
    expectRefusedWithoutEffect(fn () => app(T07CancelTask::class)->handle($this->owner, $this->task, 'x'), condition: 'required_result');
    expectRefusedWithoutEffect(fn () => app(T07CancelTask::class)->handle($this->f->sales, taskOf($this->f->order, 'site_photos'), 'x'), AuthorizationException::class);

    $photos = taskOf($this->f->order, 'site_photos');
    app(T07CancelTask::class)->handle($this->owner, $photos, 'لا حاجة للصور');
    expect($photos->refresh()->status)->toBe(TaskStatus::Cancelled)->and($photos->cancel_reason)->toBe('لا حاجة للصور');
});

it('routes inbox work, lets an eligible member claim it once and refuses the second claim', function () {
    $f = inboxFixture($this->f);
    $task = taskOf($f->inbox, 'needs');
    $other = $f->org->member('supervisor', [$f->org->a], $f->org->d1);

    expect($task->status)->toBe(TaskStatus::Unassigned)
        ->and($task->department_id)->toBe($f->org->d1->id)
        ->and($task->follow_up_user_id)->toBe($f->sales->id)
        ->and(UserNotification::query()->where('user_id', $f->sales->id)->where('type', 'task.unassigned')->count())->toBe(3);

    // The executor holds P-24 only for work assigned to them: the inbox is not theirs to take.
    expectRefusedWithoutEffect(fn () => app(T10ClaimTask::class)->handle($f->executor, $task), AuthorizationException::class);
    // Sales of A sees the task by branch but is outside department D1: not eligible.
    expectRefusedWithoutEffect(fn () => app(T10ClaimTask::class)->handle($f->sales, $task), condition: 'not_eligible');

    app(T10ClaimTask::class)->handle($f->supervisor, $task);
    expect($task->refresh()->assignee_id)->toBe($f->supervisor->id)->and($task->claimed_at)->not->toBeNull();

    // The later attempt (claim or manual assignment) sees the current holder and changes nothing.
    expectRefusedWithoutEffect(fn () => app(T10ClaimTask::class)->handle($other, $task), condition: 'not_from_assigned');
    expectRefusedWithoutEffect(fn () => app(T01AssignTask::class)->handle($f->org->owner, $task, $other->id), condition: 'not_from_assigned');
});

it('M-07-T01: a supervisor assigns inbox work to an eligible member of the department only', function () {
    $f = inboxFixture($this->f);
    $task = taskOf($f->inbox, 'measure');
    $outsider = $f->org->member('executor', [$f->org->a]); // no department

    expect(fn () => app(T01AssignTask::class)->handle($f->supervisor, $task, $outsider->id))->toThrow(ValidationException::class);
    expectRefusedWithoutEffect(fn () => app(T01AssignTask::class)->handle($f->executor, $task, $f->executor->id), AuthorizationException::class);

    app(T01AssignTask::class)->handle($f->supervisor, $task, $f->executor->id);
    expect($task->refresh()->status)->toBe(TaskStatus::Assigned)->and($task->assignee_id)->toBe($f->executor->id);

    // The executor now sees and runs exactly this task (P-24 assigned), not the rest of the order.
    expect(Access::can($f->executor, 'P-24', $task))->toBeTrue()
        ->and(Access::can($f->executor, 'P-24', taskOf($f->inbox, 'needs')))->toBeFalse()
        ->and(Access::can($f->executor, 'P-16', $f->inbox))->toBeFalse();
    app(T02StartTask::class)->handle($f->executor, $task);
});

it('routes unassigned work to another active department within scope only', function () {
    $f = inboxFixture($this->f);
    $task = taskOf($f->inbox, 'needs');

    expect(fn () => app(T01AssignTask::class)->handle($f->supervisor, $task, null, $f->org->d2->id))->toThrow(ValidationException::class);
    app(T01AssignTask::class)->handle($f->org->owner, $task, null, $f->org->d2->id);
    expect($task->refresh()->department_id)->toBe($f->org->d2->id)->and($task->status)->toBe(TaskStatus::Unassigned);
});

it('M-07-T09: disabling a member releases their open work to its inbox or follower and blocks new assignment', function () {
    $f = inboxFixture($this->f);
    $task = taskOf($f->inbox, 'needs');
    app(T01AssignTask::class)->handle($f->supervisor, $task, $f->executor->id);
    app(T02StartTask::class)->handle($f->executor, $task);
    $direct = taskOf($this->f->order, 'needs'); // first fixture order: assigned to sales directly

    app(T01DisableMembership::class)->handle($f->org->owner, Org::membershipOf($f->executor), 'انتهاء العقد');
    app(T01DisableMembership::class)->handle($f->org->owner, Org::membershipOf($this->f->sales), 'نقل');

    $task->refresh();
    $t = StateTransition::query()->where('machine', 'M-07')->where('transition', 'T09')->where('subject_id', $task->id)->firstOrFail();
    expect($task->status)->toBe(TaskStatus::Unassigned)
        ->and($task->assignee_id)->toBeNull()
        ->and($task->department_id)->toBe($f->org->d1->id)
        ->and($t->context['previous_assignee_id'])->toBe($f->executor->id)
        ->and($t->reason)->toBe('انتهاء العقد')
        // Sales (the follower) was disabled too: follow-up moves to the owner, never to no one.
        ->and($direct->refresh()->status)->toBe(TaskStatus::Unassigned)
        ->and($direct->follow_up_user_id)->toBe($f->org->owner->id)
        ->and(UserNotification::query()->where('user_id', $f->org->owner->id)->where('type', 'task.unassigned')->exists())->toBeTrue();

    // A disabled member receives no new work; an eligible one does.
    expect(fn () => app(T01AssignTask::class)->handle($f->supervisor, $task, $f->executor->id))->toThrow(ValidationException::class);
    $replacement = $f->org->member('executor', [$f->org->a], $f->org->d1);
    app(T01AssignTask::class)->handle($f->supervisor, $task, $replacement->id);
    expect($task->refresh()->assignee_id)->toBe($replacement->id)->and($task->status)->toBe(TaskStatus::Assigned);
});

it('reports an unknown task transition code', function () {
    expectRefusedWithoutEffect(fn () => Transition::check(StateMachine::M07, TaskStatus::transitions(), TaskStatus::Assigned, 'T11'), condition: 'undefined');
    expect(Task::query()->count())->toBeGreaterThan(0);
});
