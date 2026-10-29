<?php

/**
 * Binding scenario 17 «موعدان أو تعطيل موظف أو تغير صلاحية» («اختبارات ترابط ملزمة»): confirming an appointment re-checks
 * the actual resource (every booked member, not the team's name); a disabled member loses access at once (the next
 * request of an earlier session) and their open task goes to a known responsible person; a notification link never
 * restores a permission that was removed.
 *
 * Traces: SRS-ORG-06 (disable, revoke sessions at once, keep work with a known responsible), SRS-SHR-03 (two concurrent
 * bookings of the same resource are confirmed only when the «conflicting appointments» setting allows it, by an
 * explicit P-54 decision with a mandatory reason, the executor told and the «conflicted» flag; the customer's P-70
 * confirmation never bypasses a conflict), NFR-02 (deny by default; removing a grant or disabling a member voids the
 * action at once even with an earlier session or a visible button); M-16, M-07-T09, M-22-T01/T03.
 *
 * Actors over HTTP: the owner (work settings, team access, disabling), the sales member (P-54 scheduling and
 * confirming), the customer in `/portal`, the executor on `/tasks`, `/appointments`, `/notifications`.
 */

use App\Enums\AppointmentStatus;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\StateTransition;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\Org;

require_once __DIR__.'/AcceptancePortalHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->f = workflowFixture();
    $this->org = $this->f->org;
    $this->owner = $this->org->owner;
    $this->sales = $this->f->sales;
    $this->tech = $this->org->member('executor', [$this->org->a], $this->org->d1);
    $this->tech2 = $this->org->member('executor', [$this->org->a], $this->org->d1);
    $this->day = '2026-11-03'; // a Tuesday (working day)
    $this->customer = $this->f->order->customer;
    $this->customer->forceFill(['portal_login_enabled' => true])->save();
});

/**
 * ApptSchedule as the sales member: a proposal (reserves nothing) or a proposal sent to the customer.
 *
 * @param  list<int>  $members
 */
function accP17Propose(object $t, array $members, string $from, string $to, string $mode = 'propose'): Appointment
{
    accPStaff($t->sales);
    $t->post('/appointments', [
        'order_id' => $t->f->order->id, 'kind' => 'measurement', 'starts_at' => $t->day.'T'.$from, 'ends_at' => $t->day.'T'.$to,
        'address' => 'الرياض، حي النرجس', 'member_ids' => $members, 'confirm_mode' => $mode,
    ])->assertSessionHasNoErrors()->assertRedirect();

    return Appointment::query()->latest('id')->firstOrFail();
}

function accP17Confirm(object $t, Appointment $a, ?string $reason = null): TestResponse
{
    accPStaff($t->sales);

    return $t->from("/appointments/{$a->id}")->post("/appointments/{$a->id}/confirm", array_filter([
        'confirmation_method' => 'phone', 'lock_version' => $a->refresh()->lock_version, 'conflict_reason' => $reason,
    ]));
}

it('under «block» the confirmation re-checks every booked member: the team clashing through one member is refused, and the customer cannot confirm it either', function () {
    accPStaff($this->owner);
    $this->put('/settings/work', [...WorkSettings::DEFAULTS, 'conflicting_appointments' => 'block'])->assertRedirect('/settings/work');

    // Two proposals for overlapping times: proposing reserves nothing, so both exist.
    $first = accP17Propose($this, [$this->tech->id], '10:00', '11:00');
    $team = accP17Propose($this, [$this->tech2->id, $this->tech->id], '10:30', '11:30', 'send');
    expect($first->status)->toBe(AppointmentStatus::Proposed)->and($team->status)->toBe(AppointmentStatus::AwaitingConfirmation);

    accP17Confirm($this, $first)->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe(AppointmentStatus::Confirmed)->and($first->conflicted)->toBeFalse();

    // The team's second member is free, but the first is booked: refused at confirmation, nothing written.
    $audits = DB::table('audit_events')->count();
    accP17Confirm($this, $team)->assertSessionHasErrors(['conflict' => __('workflow.appointment.conflict_blocked'), 'conflict_condition']);
    expect($team->refresh()->status)->toBe(AppointmentStatus::AwaitingConfirmation)
        ->and(DB::table('audit_events')->count())->toBe($audits);

    // The customer's own confirmation (P-70) does not bypass the conflict.
    accPFreshBrowser();
    portalSignIn($this->customer);
    $this->from('/portal')->post("/portal/appointments/{$team->id}/confirm")->assertSessionHasErrors('appointment');
    expect($team->refresh()->status)->toBe(AppointmentStatus::AwaitingConfirmation)
        ->and(Appointment::query()->where('status', AppointmentStatus::Confirmed->value)->pluck('id')->all())->toBe([$first->id]);
});

it('under «allow with reason» only an explicit P-54 decision with a reason confirms the clash: flagged on both, reason kept, executors told', function () {
    $first = accP17Propose($this, [$this->tech->id], '10:00', '11:00');
    accP17Confirm($this, $first)->assertSessionHasNoErrors();
    $team = accP17Propose($this, [$this->tech2->id, $this->tech->id], '10:30', '11:30', 'send');

    // The customer cannot confirm it (P-70 never bypasses), and staff need a reason.
    accPFreshBrowser();
    portalSignIn($this->customer);
    $this->from('/portal')->post("/portal/appointments/{$team->id}/confirm")->assertSessionHasErrors('appointment');
    accP17Confirm($this, $team)->assertSessionHasErrors(['conflict' => __('workflow.appointment.conflict_reason_required')]);
    expect($team->refresh()->status)->toBe(AppointmentStatus::AwaitingConfirmation);

    accP17Confirm($this, $team, 'العميل لا يتاح إلا في هذا الوقت')->assertSessionHasNoErrors();
    expect($team->refresh()->status)->toBe(AppointmentStatus::Confirmed)
        ->and($team->conflicted)->toBeTrue()
        ->and($team->conflict_reason)->toBe('العميل لا يتاح إلا في هذا الوقت')
        ->and($team->confirmation_method)->toBe('phone')
        ->and($team->confirmed_by)->toBe($this->sales->id)
        ->and($first->refresh()->conflicted)->toBeTrue()
        ->and(UserNotification::query()->where('type', 'appointment.conflict')->where('user_id', $this->tech->id)->exists())->toBeTrue()
        ->and(UserNotification::query()->where('type', 'appointment.conflict')->where('user_id', $this->tech2->id)->exists())->toBeTrue()
        ->and(StateTransition::query()->where('machine', 'M-16')->where('transition', 'T02')->count())->toBe(2)
        ->and(DB::table('audit_events')->where('action', 'appointment.confirmed')->count())->toBe(2);
});

it('a disabled member loses access on the next request of their open session; their task goes back to the inbox under a known responsible', function () {
    $task = taskOf($this->f->order, 'measure');
    accPStaff($this->owner);
    $task->status === TaskStatus::Unassigned
        ? $this->postJson("/tasks/{$task->id}/assign", ['assignee_id' => $this->tech->id, 'lock_version' => $task->lock_version])->assertOk()
        : $this->postJson("/tasks/{$task->id}/reassign", ['assignee_id' => $this->tech->id, 'reason' => 'فني القياس', 'lock_version' => $task->lock_version])->assertOk();
    expect($task->refresh()->assignee_id)->toBe($this->tech->id)->and($task->status)->toBe(TaskStatus::Assigned);

    // The executor works in their own browser: the session is stamped.
    accPStaff($this->tech);
    $this->getJson('/tasks')->assertOk();
    $stamp = session('access_version');

    accPStaff($this->owner);
    $this->post(route('org.team.member.disable', Org::membershipOf($this->tech)), ['reason' => 'انتهاء العقد'])->assertRedirect(route('org.team.index'));

    // Same earlier session, next request: refused and signed out — the screen, the JSON API and an action button alike.
    accPFreshBrowser();
    $this->actingAs($this->tech)->withSession(['access_version' => $stamp]);
    $this->get('/tasks')->assertRedirect(route('login'));
    $this->assertGuest('web');
    $this->actingAs($this->tech)->withSession(['access_version' => $stamp]);
    $this->postJson("/tasks/{$task->id}/start", ['lock_version' => $task->refresh()->lock_version])->assertUnauthorized();
    // Even a fresh sign-in session is refused while disabled.
    accPStaff($this->tech);
    $this->get('/dashboard')->assertRedirect(route('login'));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Unassigned)
        ->and($task->assignee_id)->toBeNull()
        // A known responsible person follows it: the order's active follower (its assignee).
        ->and($task->follow_up_user_id)->toBe($this->f->order->refresh()->assignee_id)
        ->and($task->follow_up_user_id)->not->toBeNull()
        ->and($task->follow_up_user_id)->not->toBe($this->tech->id)
        ->and(StateTransition::query()->where('machine', 'M-07')->where('transition', 'T09')->count())->toBe(1)
        ->and(StateTransition::query()->where('machine', 'M-22')->where('transition', 'T01')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'membership.disabled')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'task.released')->count())->toBe(1);

    // The released task is visible for redistribution to someone who may assign it.
    accPStaff($this->owner);
    $this->getJson('/tasks?view=inbox')->assertOk();
    $this->postJson("/tasks/{$task->id}/assign", ['assignee_id' => $this->tech2->id, 'lock_version' => $task->lock_version])->assertOk();
    expect($task->refresh()->assignee_id)->toBe($this->tech2->id);
});

it('after a grant is removed, an older session is signed out and the notification link does not reopen the record', function () {
    $appointment = accP17Propose($this, [$this->tech->id], '10:00', '11:00');
    accP17Confirm($this, $appointment)->assertSessionHasNoErrors();
    $notice = UserNotification::query()->where('user_id', $this->tech->id)->where('type', 'appointment.confirmed')->sole();

    // While booked with P-24, the executor opens the link and the appointment.
    accPStaff($this->tech);
    $target = (string) $this->get(route('notifications.open', $notice))->assertRedirect()->headers->get('Location');
    expect($target)->not->toBe(route('dashboard'));
    $this->get("/appointments/{$appointment->id}")->assertOk();
    $stamp = session('access_version');

    // The owner moves them to a role without any order or appointment access (content editor).
    accPStaff($this->owner);
    $this->put(route('org.team.member.access', Org::membershipOf($this->tech)), ['roles' => [['role_template_id' => Org::templateId('content'), 'scope' => '']]])
        ->assertRedirect(route('org.team.member', Org::membershipOf($this->tech)));

    // The earlier session is signed out at its next request.
    accPFreshBrowser();
    $this->actingAs($this->tech)->withSession(['access_version' => $stamp]);
    $this->get("/appointments/{$appointment->id}")->assertRedirect(route('login'));

    // Signed in again: the old notification link no longer leads to the record, and the record itself is refused.
    accPStaff(User::query()->findOrFail($this->tech->id));
    $this->get(route('notifications.open', $notice))->assertRedirect(route('dashboard'));
    expect($this->get($target)->status())->toBeIn([403, 404])
        ->and($this->get("/appointments/{$appointment->id}")->status())->toBeIn([403, 404])
        ->and(DB::table('audit_events')->where('action', 'membership.access_updated')->count())->toBe(1);
});
