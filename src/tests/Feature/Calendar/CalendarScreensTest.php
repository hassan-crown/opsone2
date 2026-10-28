<?php

/**
 * Calendar and appointment screens (P5, SHR-04): scope on every read, the P-54 gate on writes, the facility conflict
 * policy through the HTTP layer, the clash preview that hides out-of-scope appointments, stale-version refusal on a
 * reschedule, facility-time-zone handling and the work-settings screen.
 */

use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\WorkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = workflowFixture();
    $this->org = $this->f->org;
    $this->owner = $this->org->owner;
    $this->tech = $this->org->member('executor', [$this->org->a]);
    $this->tech2 = $this->org->member('executor', [$this->org->a]);
    $this->day = '2026-11-03'; // a Tuesday
});

function scheduleBody(object $test, array $overrides = []): array
{
    return [
        'order_id' => $test->f->order->id, 'kind' => 'measurement', 'starts_at' => $test->day.'T10:00', 'ends_at' => $test->day.'T11:00',
        'address' => 'الرياض، حي النرجس', 'member_ids' => [$test->tech->id], 'confirm_mode' => 'propose',
        ...$overrides,
    ];
}

function confirmedAt(object $test, array $memberIds, string $start = '10:00', string $end = '11:00'): Appointment
{
    $a = propose($test->f->sales, $test->f->order, $memberIds, $test->day.' '.$start, $test->day.' '.$end);
    app(T02ConfirmAppointment::class)->handle($test->f->sales, $a, 'phone');

    return $a->refresh();
}

it('shows the week with local times, the maps link and day-off flags', function () {
    $a = confirmedAt($this, [$this->tech->id]);

    $this->actingAs($this->f->sales)->get('/calendar?date='.$this->day)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('calendar/index')
            ->where('from', '2026-11-01') // Sunday
            ->has('days', 7)
            ->where('days.5.working', false) // Friday
            ->where('timezone', 'Asia/Riyadh')
            ->has('appointments', 1)
            ->where('appointments.0.id', $a->id)
            ->where('appointments.0.date', $this->day)
            ->where('appointments.0.start', '10:00')
            ->where('appointments.0.maps_url', fn ($url) => str_starts_with($url, 'https://www.google.com/maps/search/')));

    $this->actingAs($this->f->sales)->get('/calendar?view=day&date='.$this->day)
        ->assertInertia(fn (Assert $page) => $page->where('view', 'day')->has('days', 1)->has('appointments', 1));
});

it('hides out-of-scope appointments from the calendar and the detail', function () {
    $a = confirmedAt($this, [$this->tech->id]);
    $salesC = $this->org->member('sales', [$this->org->c]);

    $this->actingAs($salesC)->get('/calendar?date='.$this->day)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('appointments', 0));
    $this->actingAs($salesC)->get('/appointments/'.$a->id)->assertNotFound();
    $this->actingAs($salesC)->post("/appointments/{$a->id}/cancel", ['reason' => 'x'])->assertForbidden();
    expect($a->refresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('shows a booked member only their visit, without the customer', function () {
    confirmedAt($this, [$this->tech->id]);

    $this->actingAs($this->tech)->get('/calendar?date='.$this->day)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('appointments', 1)
            ->where('appointments.0.customer', null)
            ->where('appointments.0.can_manage', false)
            ->where('canSchedule', false));
    // Another member of the same branch who is not booked sees nothing.
    $this->actingAs($this->tech2)->get('/calendar?date='.$this->day)
        ->assertInertia(fn (Assert $page) => $page->has('appointments', 0));
});

it('keeps scheduling behind P-54', function () {
    $this->actingAs($this->tech)->get('/appointments/new')->assertForbidden();
    $this->actingAs($this->tech)->post('/appointments', scheduleBody($this))->assertForbidden();
    $this->actingAs($this->tech)->get('/calendar/conflicts?'.http_build_query([
        'member_ids' => [$this->tech->id], 'starts_at' => $this->day.'T10:00', 'ends_at' => $this->day.'T11:00',
    ]))->assertForbidden();

    $this->actingAs($this->f->sales)->get('/appointments/new?order='.$this->f->order->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('calendar/schedule')->where('orderId', $this->f->order->id)->has('orders', 1));
    expect(Appointment::query()->count())->toBe(0);
});

it('stores facility-local times as UTC and walks propose → send → confirm', function () {
    $response = $this->actingAs($this->f->sales)->post('/appointments', scheduleBody($this, ['confirm_mode' => 'send']));
    $a = Appointment::query()->firstOrFail();
    $response->assertRedirect('/appointments/'.$a->id);

    expect($a->status)->toBe(AppointmentStatus::AwaitingConfirmation)
        ->and($a->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-03 07:00'); // 10:00 in Riyadh (UTC+3)

    $this->actingAs($this->f->sales)->post("/appointments/{$a->id}/confirm", ['confirmation_method' => 'phone', 'lock_version' => $a->lock_version])->assertRedirect();
    expect($a->refresh()->status)->toBe(AppointmentStatus::Confirmed);

    $this->actingAs($this->f->sales)->get('/appointments/'.$a->id)
        ->assertInertia(fn (Assert $page) => $page
            ->component('calendar/appointment')
            ->where('appointment.starts_at', '2026-11-03T10:00')
            ->where('can.requestChange', true)
            ->where('can.confirm', false));
});

it('validates the schedule form', function () {
    $this->actingAs($this->f->sales)->post('/appointments', scheduleBody($this, ['ends_at' => $this->day.'T09:00']))->assertSessionHasErrors('ends_at');
    $this->actingAs($this->f->sales)->post('/appointments', scheduleBody($this, ['member_ids' => []]))->assertSessionHasErrors('member_ids');
    $this->actingAs($this->f->sales)->post('/appointments', scheduleBody($this, ['confirm_mode' => 'confirm']))->assertSessionHasErrors('confirmation_method');
    $this->actingAs($this->f->sales)->post('/appointments', scheduleBody($this, ['address' => '']))->assertSessionHasErrors('address');
    expect(Appointment::query()->count())->toBe(0);
});

it('refuses a conflicting confirmation under «block» and leaves no stray proposal', function () {
    confirmedAt($this, [$this->tech->id]);
    blockConflicts($this->owner);

    $this->actingAs($this->f->sales)->from('/appointments/new')
        ->post('/appointments', scheduleBody($this, ['confirm_mode' => 'confirm', 'confirmation_method' => 'phone', 'starts_at' => $this->day.'T10:30', 'ends_at' => $this->day.'T11:30']))
        ->assertSessionHasErrors(['conflict' => __('workflow.appointment.conflict_blocked'), 'conflict_condition']);

    expect(Appointment::query()->count())->toBe(1);

    // A plain proposal is still allowed: it reserves nothing.
    $this->actingAs($this->f->sales)->post('/appointments', scheduleBody($this, ['starts_at' => $this->day.'T10:30', 'ends_at' => $this->day.'T11:30']))->assertRedirect();
    expect(Appointment::query()->count())->toBe(2);
});

it('allows a conflicting confirmation only with a reason when the policy is «allow with reason»', function () {
    $first = confirmedAt($this, [$this->tech->id]);
    $body = scheduleBody($this, ['confirm_mode' => 'confirm', 'confirmation_method' => 'phone', 'starts_at' => $this->day.'T10:30', 'ends_at' => $this->day.'T11:30']);

    $this->actingAs($this->f->sales)->post('/appointments', $body)
        ->assertSessionHasErrors(['conflict' => __('workflow.appointment.conflict_reason_required')]);
    expect(Appointment::query()->count())->toBe(1);

    $this->actingAs($this->f->sales)->post('/appointments', [...$body, 'conflict_reason' => 'العميل طلب الوقت نفسه'])->assertSessionHasNoErrors()->assertRedirect();

    $second = Appointment::query()->latest('id')->firstOrFail();
    expect($second->status)->toBe(AppointmentStatus::Confirmed)
        ->and($second->conflicted)->toBeTrue()
        ->and($second->conflict_reason)->toBe('العميل طلب الوقت نفسه')
        ->and($first->refresh()->conflicted)->toBeTrue();
});

it('previews clashes within the viewer scope only, counting the rest', function () {
    $both = $this->org->member('executor', [$this->org->a, $this->org->c]);
    $orderC = openCustomOrder($this->org->member('sales', [$this->org->c]), $this->f->inC);
    $clashC = propose($this->org->owner, $orderC, [$both->id], $this->day.' 14:00', $this->day.' 15:00');
    app(T02ConfirmAppointment::class)->handle($this->org->owner, $clashC, 'phone');
    $inA = propose($this->f->sales, $this->f->order, [$both->id], $this->day.' 14:30', $this->day.' 15:30');
    app(T02ConfirmAppointment::class)->handle($this->org->owner, $inA, 'phone', 'سبب');

    $query = http_build_query(['member_ids' => [$both->id], 'starts_at' => $this->day.'T14:15', 'ends_at' => $this->day.'T14:45']);

    // Sales of branch A sees their own clash and only a count for branch C's.
    $this->actingAs($this->f->sales)->getJson('/calendar/conflicts?'.$query)->assertOk()
        ->assertJsonPath('hidden', 1)
        ->assertJsonCount(1, 'conflicts')
        ->assertJsonPath('conflicts.0.id', $inA->id)
        ->assertJsonPath('allows_conflicts', true);

    // The owner sees both and no hidden ones; the excluded appointment is skipped (moving it).
    $this->actingAs($this->owner)->getJson('/calendar/conflicts?'.$query)
        ->assertJsonPath('hidden', 0)->assertJsonCount(2, 'conflicts');
    $this->actingAs($this->owner)->getJson('/calendar/conflicts?'.$query.'&exclude='.$inA->id)
        ->assertJsonCount(1, 'conflicts')->assertJsonPath('conflicts.0.id', $clashC->id);
});

it('keeps the original time until a change is approved, and refuses a stale version', function () {
    $a = confirmedAt($this, [$this->tech->id]);
    $version = $a->lock_version;
    $change = ['starts_at' => $this->day.'T13:00', 'ends_at' => $this->day.'T14:00', 'reason' => 'العميل غير متاح', 'lock_version' => $version];

    $this->actingAs($this->f->sales)->post("/appointments/{$a->id}/change", $change)->assertSessionHasNoErrors()->assertRedirect();
    $a->refresh();
    expect($a->starts_at->utc()->format('H:i'))->toBe('07:00') // original stays booked
        ->and($a->pending_change['starts_at'])->toStartWith('2026-11-03T10:00:00') // 13:00 Riyadh
        ->and($a->pending_change['reason'])->toBe('العميل غير متاح');

    // A second tab still holds the old version: its reschedule is refused and changes nothing.
    $this->actingAs($this->f->sales)->post("/appointments/{$a->id}/change/discard", ['reason' => 'x', 'lock_version' => $version])
        ->assertSessionHasErrors('lock_version');
    expect($a->refresh()->pending_change)->not->toBeNull();

    $this->actingAs($this->f->sales)->post("/appointments/{$a->id}/change/approve", ['lock_version' => $a->lock_version])->assertSessionHasNoErrors();
    $a->refresh();
    expect($a->starts_at->utc()->format('H:i'))->toBe('10:00')->and($a->pending_change)->toBeNull();

    // The same stale version cannot move it again.
    $this->actingAs($this->f->sales)->post("/appointments/{$a->id}/change", [...$change, 'starts_at' => $this->day.'T15:00', 'ends_at' => $this->day.'T16:00'])
        ->assertSessionHasErrors('lock_version');
});

it('cancels with a required reason and records the outcome', function () {
    $a = confirmedAt($this, [$this->tech->id]);

    $this->actingAs($this->f->sales)->post("/appointments/{$a->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
    expect($a->refresh()->status)->toBe(AppointmentStatus::Confirmed);

    // A booked member records the outcome (WF-08); the visit is not «done» for a no-show.
    $this->actingAs($this->tech)->post("/appointments/{$a->id}/complete", ['outcome' => 'العميل غير موجود', 'no_show' => true])->assertSessionHasNoErrors();
    expect($a->refresh()->status)->toBe(AppointmentStatus::Confirmed)->and($a->no_show)->toBeTrue();

    $this->actingAs($this->f->sales)->post("/appointments/{$a->id}/cancel", ['reason' => 'تعذّر الوصول'])->assertSessionHasNoErrors();
    expect($a->refresh()->status)->toBe(AppointmentStatus::Cancelled)->and($a->cancel_reason)->toBe('تعذّر الوصول');
});

it('lets only P-02 save the work settings and validates them', function () {
    $valid = [...WorkSettings::DEFAULTS, 'conflicting_appointments' => 'block', 'appointment_slot_minutes' => 90];

    $this->actingAs($this->owner)->get('/settings/work')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('account/settings-work')->where('canEdit', true)->where('settings.appointment_slot_minutes', 60));

    $this->actingAs($this->f->sales)->put('/settings/work', $valid)->assertForbidden();
    expect(WorkSettings::current()->conflicting_appointments)->toBe('allow_with_reason');

    $this->actingAs($this->owner)->put('/settings/work', $valid)->assertRedirect('/settings/work');
    expect(WorkSettings::current()->conflicting_appointments)->toBe('block')->and(WorkSettings::current()->appointment_slot_minutes)->toBe(90);

    foreach ([
        'no days' => [['working_days' => []], 'working_days'],
        'bad day' => [['working_days' => [0, 9]], 'working_days.0'],
        'slot' => [['appointment_slot_minutes' => 50], 'appointment_slot_minutes'],
        'policy' => [['conflicting_appointments' => 'maybe'], 'conflicting_appointments'],
        'time' => [['work_starts_at' => '25:00'], 'work_starts_at'],
        'hours order' => [['work_starts_at' => '17:00', 'work_ends_at' => '08:00'], 'work_ends_at'],
        'reminder order' => [['reminder_from' => '20:00', 'reminder_until' => '09:00'], 'reminder_until'],
    ] as $case) {
        [$override, $field] = $case;
        $this->actingAs($this->owner)->put('/settings/work', [...$valid, ...$override])->assertSessionHasErrors($field);
    }
    expect(WorkSettings::current()->appointment_slot_minutes)->toBe(90);
});

it('puts the calendar in the main navigation only for those who can use it', function () {
    $this->actingAs($this->f->sales)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('orgNav.calendar', true));
    $outsider = $this->org->member(null, []);
    $this->actingAs($outsider)->get('/calendar')->assertForbidden();
});
