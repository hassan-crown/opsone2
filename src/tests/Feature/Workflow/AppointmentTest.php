<?php

/**
 * Appointments (M-16, SHR-03/04, Q-33 a): proposals reserve nothing, confirmation checks conflicts atomically per
 * member under the facility policy, a change keeps the original until approved, outcomes and cancellation.
 */

use App\Actions\Settings\UpdateWorkSettings;
use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\StateMachines\M16Appointment\T01SendToCustomer;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Actions\StateMachines\M16Appointment\T03RequestChange;
use App\Actions\StateMachines\M16Appointment\T04ApproveChange;
use App\Actions\StateMachines\M16Appointment\T05CompleteVisit;
use App\Actions\StateMachines\M16Appointment\T06CancelAppointment;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\StateTransition;
use App\Models\UserNotification;
use App\Models\WorkSettings;
use App\Workflow\AppointmentConflict;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = workflowFixture();
    $this->owner = $this->f->org->owner;
    $this->tech = $this->f->org->member('executor', [$this->f->org->a]);
    $this->tech2 = $this->f->org->member('executor', [$this->f->org->a]);
});

it('proposes without reserving, with a warning on overlap, and a maps link (Q-33 a)', function () {
    $first = propose($this->f->sales, $this->f->order, [$this->tech->id]);
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $first, 'phone');

    $result = app(T00ProposeAppointment::class)->handle($this->f->sales, $this->f->order, [
        'kind' => 'installation', 'starts_at' => '2026-11-03 10:00 Asia/Riyadh', 'address' => 'العنوان', 'member_ids' => [$this->tech->id, $this->tech2->id],
        'latitude' => 24.7, 'longitude' => 46.6,
    ]);

    expect($result['conflicts'])->toBe([$first->id])
        ->and($result['appointment']->status)->toBe(AppointmentStatus::Proposed)
        ->and($result['appointment']->ends_at->diffInMinutes($result['appointment']->starts_at, true))->toEqual(60) // one slot
        ->and($result['appointment']->mapsUrl())->toBe('https://www.google.com/maps/search/?api=1&query=24.7%2C46.6');
});

it('validates members, times and P-54', function () {
    $salesC = $this->f->org->member('sales', [$this->f->org->c]);

    expect(fn () => propose($this->f->sales, $this->f->order, [$salesC->id]))->toThrow(ValidationException::class)
        ->and(fn () => propose($this->f->sales, $this->f->order, []))->toThrow(ValidationException::class)
        ->and(fn () => propose($this->f->sales, $this->f->order, [$this->tech->id], '2026-11-03 09:00', '2026-11-03 08:00'))->toThrow(ValidationException::class)
        ->and(fn () => propose($this->tech, $this->f->order, [$this->tech->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => propose($salesC, $this->f->order, [$salesC->id]))->toThrow(AuthorizationException::class)
        ->and(Appointment::query()->count())->toBe(0);
});

it('walks T01 → T02 → T05 and refuses undefined moves without effect', function () {
    $a = propose($this->f->sales, $this->f->order, [$this->tech->id]);
    expectRefusedWithoutEffect(fn () => app(T05CompleteVisit::class)->handle($this->owner, $a, 'تم'), condition: 'not_from_proposed');
    expectRefusedWithoutEffect(fn () => app(T03RequestChange::class)->handle($this->owner, $a, '2026-11-04 09:00', '2026-11-04 10:00', 'x'), condition: 'not_from_proposed');

    app(T01SendToCustomer::class)->handle($this->f->sales, $a);
    expectRefusedWithoutEffect(fn () => app(T01SendToCustomer::class)->handle($this->f->sales, $a), condition: 'not_from_awaiting_confirmation');
    expect(fn () => app(T02ConfirmAppointment::class)->handle($this->f->sales, $a, 'pigeon'))->toThrow(ValidationException::class);

    app(T02ConfirmAppointment::class)->handle($this->f->sales, $a, 'phone');
    expect($a->refresh()->status)->toBe(AppointmentStatus::Confirmed)
        ->and($a->confirmation_method)->toBe('phone')
        ->and(UserNotification::query()->where('user_id', $this->tech->id)->where('type', 'appointment.confirmed')->exists())->toBeTrue();

    // A booked member with P-24 records the outcome; a no-show is a marker, not «تم».
    app(T05CompleteVisit::class)->handle($this->tech, $a, 'العميل غير موجود', noShow: true);
    expect($a->refresh()->status)->toBe(AppointmentStatus::Confirmed)->and($a->no_show)->toBeTrue();
    expectRefusedWithoutEffect(fn () => app(T05CompleteVisit::class)->handle($this->tech2, $a, 'تم'), AuthorizationException::class);
    app(T05CompleteVisit::class)->handle($this->tech, $a, 'تم القياس');
    expect($a->refresh()->status)->toBe(AppointmentStatus::Done);
    expectRefusedWithoutEffect(fn () => app(T06CancelAppointment::class)->handle($this->owner, $a, 'x'), condition: 'not_from_done');
});

it('refuses a conflicting confirmation under «block» and keeps the reason under «allow with reason»', function () {
    $first = propose($this->f->sales, $this->f->order, [$this->tech->id]);
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $first, 'phone');
    $second = propose($this->f->sales, $this->f->order, [$this->tech2->id, $this->tech->id], '2026-11-03 10:30', '2026-11-03 12:00');

    // Default «allow with reason»: no reason → refused, with the clash listed.
    try {
        expectRefusedWithoutEffect(fn () => app(T02ConfirmAppointment::class)->handle($this->f->sales, $second, 'phone'), AppointmentConflict::class, 'conflict_reason_required');
        app(T02ConfirmAppointment::class)->handle($this->f->sales, $second, 'phone');
    } catch (AppointmentConflict $e) {
        expect($e->conflicts[0]['id'])->toBe($first->id)->and($e->conflicts[0]['member_ids'])->toBe([$this->tech->id]);
    }

    // «block»: even a reason does not pass.
    blockConflicts($this->owner);
    expectRefusedWithoutEffect(fn () => app(T02ConfirmAppointment::class)->handle($this->owner, $second, 'phone', 'عاجل'), AppointmentConflict::class, 'conflict_blocked');

    // Back to «allow with reason»: accepted, both marked, members notified.
    app(UpdateWorkSettings::class)->handle($this->owner, WorkSettings::DEFAULTS);
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $second, 'phone', 'العميل لا يتاح إلا في هذا الوقت');
    expect($second->refresh()->conflicted)->toBeTrue()
        ->and($second->conflict_reason)->toBe('العميل لا يتاح إلا في هذا الوقت')
        ->and($first->refresh()->conflicted)->toBeTrue()
        ->and(UserNotification::query()->where('user_id', $this->tech->id)->where('type', 'appointment.conflict')->exists())->toBeTrue();

    // Cancelling one recomputes the other's marker; the accepted reason stays in history.
    app(T06CancelAppointment::class)->handle($this->f->sales, $second, 'العميل ألغى');
    expect($first->refresh()->conflicted)->toBeFalse()
        ->and($second->refresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($second->conflict_reason)->toBe('العميل لا يتاح إلا في هذا الوقت');
});

it('keeps the original until a change is approved, re-checking conflicts (T03/T04)', function () {
    $a = propose($this->f->sales, $this->f->order, [$this->tech->id]);
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $a, 'in_person');
    $other = propose($this->f->sales, $this->f->order, [$this->tech->id], '2026-11-05 09:00', '2026-11-05 10:00');
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $other, 'phone');
    $originalStart = $a->refresh()->starts_at;

    $result = app(T03RequestChange::class)->handle($this->f->sales, $a, '2026-11-05 09:30 Asia/Riyadh', '2026-11-05 10:30 Asia/Riyadh', 'طلب العميل');
    expect($result['conflicts'])->toBe([$other->id])
        ->and($a->refresh()->starts_at->equalTo($originalStart))->toBeTrue()
        ->and($a->pending_change['reason'])->toBe('طلب العميل');
    expectRefusedWithoutEffect(fn () => app(T03RequestChange::class)->handle($this->f->sales, $a, '2026-11-06 09:00', '2026-11-06 10:00', 'x'), condition: 'change_pending');

    // Refused alternative: the original stays booked.
    blockConflicts($this->owner);
    expectRefusedWithoutEffect(fn () => app(T04ApproveChange::class)->handle($this->f->sales, $a, 'x'), AppointmentConflict::class, 'conflict_blocked');
    app(T03RequestChange::class)->discard($this->f->sales, $a, 'لا بديل');
    expect($a->refresh()->pending_change)->toBeNull()->and($a->status)->toBe(AppointmentStatus::Confirmed);
    expectRefusedWithoutEffect(fn () => app(T04ApproveChange::class)->handle($this->f->sales, $a), condition: 'no_change_pending');

    // A free alternative is applied; moving within its own old slot is not a conflict with itself.
    app(T03RequestChange::class)->handle($this->f->sales, $a, '2026-11-03 10:00 Asia/Riyadh', '2026-11-03 12:00 Asia/Riyadh', 'تأخير ساعة');
    app(T04ApproveChange::class)->handle($this->f->sales, $a);
    expect($a->refresh()->starts_at->equalTo(CarbonImmutable::parse('2026-11-03 07:00 UTC')))->toBeTrue()
        ->and($a->pending_change)->toBeNull()
        ->and(StateTransition::query()->where('machine', 'M-16')->where('transition', 'T04')->value('context')['previous']['starts_at'])->toBe($originalStart->toIso8601String())
        ->and(UserNotification::query()->where('user_id', $this->tech->id)->where('type', 'appointment.changed')->exists())->toBeTrue();
});

it('only P-54 holders confirm, change or cancel', function () {
    $a = propose($this->f->sales, $this->f->order, [$this->tech->id]);

    expectRefusedWithoutEffect(fn () => app(T02ConfirmAppointment::class)->handle($this->tech, $a, 'phone'), AuthorizationException::class);
    expectRefusedWithoutEffect(fn () => app(T06CancelAppointment::class)->handle($this->tech, $a, 'x'), AuthorizationException::class);
    expect(fn () => app(T06CancelAppointment::class)->handle($this->owner, $a, ''))->toThrow(ValidationException::class);
    app(T06CancelAppointment::class)->handle($this->owner, $a, 'خطأ في الإدخال');
    expect($a->refresh()->status)->toBe(AppointmentStatus::Cancelled);
});
