<?php

/**
 * COM-07 measurements (P-14), facility work settings (Q-31 a, P-02) and the read services the screens use
 * (order flow, work lists, calendar).
 */

use App\Actions\Settings\UpdateWorkSettings;
use App\Actions\StateMachines\M07Task\T06ReassignTask;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Actions\Workflow\RecordMeasurement;
use App\Models\AuditEvent;
use App\Models\OrderMeasurement;
use App\Models\WorkSettings;
use App\Workflow\AppointmentQueries;
use App\Workflow\TaskQueries;
use App\Workflow\WorkflowPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = workflowFixture();
    $this->owner = $this->f->org->owner;
});

it('records and corrects measurements with P-14 over the order or the assigned measurement task', function () {
    $data = ['location' => 'جدار النافذة', 'name' => 'الارتفاع', 'value' => '2.4', 'unit' => 'm', 'kind' => 'estimated', 'measured_on' => now()->toDateString()];
    $m = app(RecordMeasurement::class)->handle($this->f->sales, $this->f->order, $data);

    expect($m->value)->toBe('2.400')->and($m->recorded_by)->toBe($this->f->sales->id)
        ->and(AuditEvent::query()->where('action', 'order.measurement_recorded')->count())->toBe(1);

    app(RecordMeasurement::class)->handle($this->f->sales, $this->f->order, [...$data, 'value' => '2.45', 'kind' => 'final'], correcting: $m);
    $event = AuditEvent::query()->where('action', 'order.measurement_corrected')->firstOrFail();
    expect($m->refresh()->value)->toBe('2.450')->and(OrderMeasurement::query()->count())->toBe(1)
        ->and($event->before['kind'])->toBe('estimated')->and($event->after['kind'])->toBe('final');

    expect(fn () => app(RecordMeasurement::class)->handle($this->f->sales, $this->f->order, [...$data, 'unit' => 'feet']))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordMeasurement::class)->handle($this->f->sales, $this->f->order, [...$data, 'measured_on' => now()->addDay()->toDateString()]))->toThrow(ValidationException::class);

    // An executor holding the measurement task records through it, and only through it.
    $tech = $this->f->org->member('executor', [$this->f->org->a]);
    $task = taskOf($this->f->order, 'measure');
    expect(fn () => app(RecordMeasurement::class)->handle($tech, $this->f->order, $data))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RecordMeasurement::class)->handle($tech, $this->f->order, $data, taskOf($this->f->order, 'needs')))->toThrow(AuthorizationException::class);
    app(T06ReassignTask::class)->handle($this->owner, $task, $tech->id, 'فني القياس');
    app(RecordMeasurement::class)->handle($tech, $this->f->order, $data, $task->refresh());
    expect(OrderMeasurement::query()->where('recorded_by', $tech->id)->where('task_id', $task->id)->exists())->toBeTrue();
});

it('keeps facility work settings with defaults, validated, P-02 only and audited', function () {
    $settings = WorkSettings::current();
    expect($settings->exists)->toBeFalse()
        ->and($settings->working_days)->toBe([7, 1, 2, 3, 4])
        ->and($settings->allowsConflicts())->toBeTrue()
        ->and($settings->isWorkingDay(CarbonImmutable::parse('2026-10-30')))->toBeFalse(); // Friday

    $sales = $this->f->sales;
    expect(fn () => app(UpdateWorkSettings::class)->handle($sales, WorkSettings::DEFAULTS))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateWorkSettings::class)->handle($this->owner, [...WorkSettings::DEFAULTS, 'work_ends_at' => '07:00']))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateWorkSettings::class)->handle($this->owner, [...WorkSettings::DEFAULTS, 'appointment_slot_minutes' => 7]))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateWorkSettings::class)->handle($this->owner, [...WorkSettings::DEFAULTS, 'working_days' => [8]]))->toThrow(ValidationException::class);

    app(UpdateWorkSettings::class)->handle($this->owner, [...WorkSettings::DEFAULTS, 'working_days' => [6, 7, 1, 2, 3], 'appointment_slot_minutes' => 90, 'conflicting_appointments' => 'block']);
    $saved = WorkSettings::current();
    expect($saved->working_days)->toBe([1, 2, 3, 6, 7])
        ->and($saved->appointment_slot_minutes)->toBe(90)
        ->and($saved->allowsConflicts())->toBeFalse()
        ->and(WorkSettings::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'work_settings.updated')->count())->toBe(1)
        ->and($saved->canSendReminderAt(CarbonImmutable::parse('2026-11-01 07:00 UTC')))->toBeTrue()   // 10:00 Riyadh
        ->and($saved->canSendReminderAt(CarbonImmutable::parse('2026-11-01 19:00 UTC')))->toBeFalse(); // 22:00 Riyadh
});

it('presents the order flow with the current stage, blockers and what the viewer may do', function () {
    $view = WorkflowPresenter::order($this->f->order, $this->owner);

    expect($view['flow_version'])->toBe(1)
        ->and($view['active_stage_id'])->toBe(stageOf($this->f->order, 'measure')->id)
        ->and(array_column($view['blockers'], 'action'))->toBe(['needs', 'measure'])
        ->and($view['blockers'][0]['responsible_user_id'])->toBe($this->f->sales->id)
        ->and($view['stages'][0]['can_complete'])->toBeTrue()
        ->and($view['stages'][0]['actions'][0]['can']['allow'])->toBeTrue()
        ->and($view['stages'])->toHaveCount(5);

    $salesView = WorkflowPresenter::order($this->f->order, $this->f->sales);
    expect($salesView['stages'][0]['can_complete'])->toBeFalse()
        ->and($salesView['stages'][0]['actions'][0]['can']['allow'])->toBeFalse()
        ->and($salesView['can']['edit_upcoming'])->toBeFalse();
});

it('lists my work, the inbox, followed work, eligible members and the calendar within access', function () {
    $tech = $this->f->org->member('executor', [$this->f->org->a]);
    $other = $this->f->org->member('sales', [$this->f->org->a]);

    expect(TaskQueries::mine($this->f->sales)->pluck('order_action_id')->count())->toBe(3)
        ->and(TaskQueries::mine($tech))->toHaveCount(0)
        ->and(TaskQueries::inbox($tech))->toHaveCount(0)
        ->and(TaskQueries::followed($this->f->sales))->toHaveCount(0);

    $eligible = TaskQueries::eligibleFor($this->owner, taskOf($this->f->order, 'needs'));
    expect(array_column($eligible, 'user_id'))->toContain($this->f->sales->id, $other->id, $tech->id)
        ->and(collect($eligible)->firstWhere('user_id', $this->f->sales->id)['open_tasks'])->toBe(3)
        ->and(TaskQueries::eligibleFor($tech, taskOf($this->f->order, 'needs')))->toBe([]);

    $a = propose($this->f->sales, $this->f->order, [$tech->id]);
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $a, 'phone');
    $from = CarbonImmutable::parse('2026-11-02');
    $to = CarbonImmutable::parse('2026-11-09');
    $salesC = $this->f->org->member('sales', [$this->f->org->c]);

    expect(AppointmentQueries::between($this->f->sales, $from, $to)->pluck('id')->all())->toBe([$a->id])
        ->and(AppointmentQueries::between($tech, $from, $to)->pluck('id')->all())->toBe([$a->id])
        ->and(AppointmentQueries::between($salesC, $from, $to))->toHaveCount(0)
        ->and(AppointmentQueries::between($this->owner, $from, $to, [$other->id]))->toHaveCount(0);
});
