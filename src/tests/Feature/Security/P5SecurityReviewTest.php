<?php

/**
 * P5 security review regressions: appointment booking bound to orders the actor can open, the clash preview window,
 * throttled P5 writes, measurement tasks after a rework, replayed results after a rework, task-path measurement
 * corrections, facility-time-zone dates and conflict notices that never name another order.
 */

use App\Actions\StateMachines\M07Task\T01AssignTask;
use App\Actions\StateMachines\M07Task\T05CompleteTask;
use App\Actions\StateMachines\M07Task\T06ReassignTask;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Actions\StateMachines\M27ActionResult\T05ReworkAction;
use App\Actions\Workflow\RecordMeasurement;
use App\Enums\ActionStatus;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\Membership;
use App\Models\OrderMeasurement;
use App\Models\Task;
use App\Models\UserNotification;
use App\Workflow\ActionResults;
use App\Workflow\Catalogue\ActionType;
use App\Workflow\TaskEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = workflowFixture();
    $this->order = $this->f->order;
    $this->owner = $this->f->org->owner;
    $this->tech = $this->f->org->member('executor', [$this->f->org->a]);
});

function measurementData(array $overrides = []): array
{
    return ['location' => 'جدار', 'name' => 'العرض', 'value' => '3.1', 'unit' => 'm', 'kind' => 'final', 'measured_on' => now()->toDateString(), ...$overrides];
}

it('refuses booking an appointment on an order the actor cannot open, even with an own-scope P-54', function () {
    // `own` P-54 passes on any new appointment (its creator is the actor): the order itself must be visible.
    $salesOwn = $this->f->org->member('sales', [$this->f->org->c], null, 'own');
    $body = [
        'order_id' => $this->order->id, 'kind' => 'measurement', 'starts_at' => '2026-11-03T10:00', 'ends_at' => '2026-11-03T11:00',
        'address' => 'الرياض', 'member_ids' => [$this->tech->id], 'confirm_mode' => 'propose',
    ];

    $this->actingAs($salesOwn)->post('/appointments', $body)->assertNotFound();
    expect(fn () => propose($salesOwn, $this->order, [$this->tech->id]))->toThrow(AuthorizationException::class)
        ->and(Appointment::query()->count())->toBe(0);

    $this->actingAs($this->f->sales)->post('/appointments', $body)->assertRedirect();
    expect(Appointment::query()->count())->toBe(1);
});

it('bounds the clash preview window like a booking', function () {
    $query = fn (string $end) => '/calendar/conflicts?'.http_build_query([
        'member_ids' => [$this->tech->id], 'starts_at' => '2026-11-03T10:00', 'ends_at' => $end,
    ]);

    $this->actingAs($this->f->sales)->getJson($query('2036-11-03T10:00'))->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    $this->actingAs($this->f->sales)->getJson($query('2026-11-03T12:00'))->assertOk();
});

it('throttles every P5 write endpoint', function (string $name) {
    $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

    expect(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue();
})->with([
    'flow.draft.save', 'flow.draft.discard', 'flow.check', 'flow.publish', 'flow.template',
    'orders.progress.complete', 'orders.progress.allow', 'orders.progress.rework', 'orders.progress.pause',
    'orders.progress.resume', 'orders.progress.finish', 'orders.progress.upcoming.save',
    'tasks.claim', 'tasks.assign', 'tasks.complete', 'tasks.measurements.store',
    'calendar.conflicts', 'appointments.store', 'appointments.send', 'appointments.confirm', 'appointments.change',
    'appointments.change.approve', 'appointments.change.discard', 'appointments.cancel', 'appointments.complete',
    'settings.work.update',
]);

it('does not complete a reworked measurement task with the measurements that were sent back', function () {
    finishActiveStage($this->owner, $this->order); // measure: a final measurement, task completed
    $measure = actionOf($this->order, 'measure');
    expect(statusOf($measure))->toBe(ActionStatus::Verified);
    $this->travel(1)->hours();

    app(T05ReworkAction::class)->handle($this->owner, $measure, 'المقاسات خاطئة');
    $task = Task::query()->where('order_action_id', $measure->id)->whereIn('status', TaskStatus::open())->firstOrFail();
    if ($task->status === TaskStatus::Unassigned) {
        $task->setRelation('order', $this->order);
        $holder = Membership::query()->where('department_id', $task->department_id)->where('status', 'active')->pluck('user_id')
            ->first(fn (int $id) => TaskEligibility::allows($id, $task));
        app(T01AssignTask::class)->handle($this->owner, $task, $holder);
    }
    $this->travel(1)->minutes();

    expect(fn () => app(T05CompleteTask::class)->handle($this->owner, $task->refresh()))->toThrow(ValidationException::class)
        ->and(statusOf($measure))->toBe(ActionStatus::Ready);

    $old = OrderMeasurement::query()->where('order_id', $this->order->id)->firstOrFail();
    app(RecordMeasurement::class)->handle($this->owner, $this->order, measurementData(['value' => '3.05']), correcting: $old);
    app(T05CompleteTask::class)->handle($this->owner, $task->refresh());
    expect(statusOf($measure))->toBe(ActionStatus::Verified);
});

it('ignores a replay of a result that a rework took back', function () {
    finishActiveStage($this->owner, $this->order);
    finishActiveStage($this->owner, $this->order); // design: quote verified from `test_source`
    $quote = actionOf($this->order, 'quote');
    app(T05ReworkAction::class)->handle($this->owner, $quote, 'تغيير البنود');

    app(ActionResults::class)->verifyFromSource($this->order, ActionType::PrepareDocument, 'quote', 'test_source', $quote->id);
    expect(statusOf($quote))->toBe(ActionStatus::Ready)->and($quote->refresh()->source_reference)->toBeNull();

    app(ActionResults::class)->verifyFromSource($this->order, ActionType::PrepareDocument, 'quote', 'agreement_document', 77);
    expect(statusOf($quote))->toBe(ActionStatus::Verified);
});

it('lets a measurement task correct only the measurements of its own action', function () {
    $byOwner = app(RecordMeasurement::class)->handle($this->owner, $this->order, measurementData());
    $task = taskOf($this->order, 'measure');
    app(T06ReassignTask::class)->handle($this->owner, $task, $this->tech->id, 'فني القياس');

    expect(fn () => app(RecordMeasurement::class)->handle($this->tech, $this->order, measurementData(['value' => '9']), $task->refresh(), $byOwner))
        ->toThrow(AuthorizationException::class)
        ->and($byOwner->refresh()->value)->toBe('3.100');

    $mine = app(RecordMeasurement::class)->handle($this->tech, $this->order, measurementData(), $task->refresh());
    app(RecordMeasurement::class)->handle($this->tech, $this->order, measurementData(['value' => '3.2']), $task->refresh(), $mine);
    expect($mine->refresh()->value)->toBe('3.200');
});

it('reads «today» in the facility time zone for measurement dates', function () {
    $this->travelTo(now()->setDate(2026, 11, 3)->setTime(22, 30)); // 01:30 on 4 Nov in Riyadh

    $m = app(RecordMeasurement::class)->handle($this->owner, $this->order, measurementData(['measured_on' => '2026-11-04']));
    expect($m->measured_on->toDateString())->toBe('2026-11-04')
        ->and(fn () => app(RecordMeasurement::class)->handle($this->owner, $this->order, measurementData(['measured_on' => '2026-11-05'])))
        ->toThrow(ValidationException::class);
});

it('tells the members of a clashing appointment about their own booking, not the other order', function () {
    $orderB = openCustomOrder($this->owner, $this->f->inB);
    $techB = $this->f->org->member('executor', [$this->f->org->b]);
    $first = propose($this->owner, $orderB, [$this->tech->id, $techB->id], '2026-11-03 10:00', '2026-11-03 11:00');
    app(T02ConfirmAppointment::class)->handle($this->owner, $first, 'phone');

    $second = propose($this->f->sales, $this->order, [$this->tech->id], '2026-11-03 10:30', '2026-11-03 11:30');
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $second, 'phone', 'العميل لا يتاح إلا في هذا الوقت');

    $notice = UserNotification::query()->where('user_id', $techB->id)->where('type', 'appointment.conflict')->firstOrFail();
    expect($notice->data['params']['order'] ?? null)->toBe($orderB->number)
        ->and($notice->target_id)->toBe($first->id)
        ->and(UserNotification::query()->where('user_id', $this->tech->id)->where('type', 'appointment.conflict')->where('target_id', $second->id)->exists())->toBeTrue();
});
