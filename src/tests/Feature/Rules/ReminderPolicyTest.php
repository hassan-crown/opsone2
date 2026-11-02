<?php

/**
 * Collection reminder policy (ADR-0037): «before» reminders, on the due date, overdue reminders at an interval after
 * the grace days and up to a cap, escalation to a role or the order's responsible person, only inside the reminder
 * hours, through the outbox and never twice; the appointment reminder lead time is configurable. P-104 only.
 */

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Rules\UpdateReminderSettings;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Finance\CollectionReminders;
use App\Models\AuditEvent;
use App\Models\CustomerMessage;
use App\Models\Finance\OrderDue;
use App\Models\ReminderSettings;
use App\Models\UserNotification;
use App\Models\WorkSettings;
use App\Whatsapp\CustomerEvents;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/RulesTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    WorkSettings::query()->create([...WorkSettings::DEFAULTS, 'reminder_from' => '00:00', 'reminder_until' => '23:59']);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function setPolicy(object $f, array $overrides = []): void
{
    app(UpdateReminderSettings::class)->handle($f->owner, [
        'before_days' => [7, 2], 'on_due' => true, 'after_every_days' => 3, 'max_after' => 2,
        'escalate_after_days' => null, 'escalate_to' => null, 'appointment_hours' => 24, ...$overrides,
    ], null);
}

/** Outbox keys of one due's customer reminders (SMS rows). */
function reminderKeys(int $dueId): array
{
    return DB::table('outbox_messages')->where('idempotency_key', 'like', "due:{$dueId}:%:sms")->orderBy('id')->pluck('idempotency_key')
        ->map(fn (string $k) => substr($k, strlen("due:{$dueId}:"), -4))->all();
}

it('picks the step of the policy for each day around the due date', function () {
    $policy = new ReminderSettings(['before_days' => [7, 2], 'on_due' => true, 'after_every_days' => 3, 'max_after' => 2]);
    $on = '2026-11-20';
    $step = fn (string $today, int $grace = 0) => CollectionReminders::step($policy, $on, $grace, $today);

    expect($step('2026-11-10'))->toBeNull()                 // 10 days before
        ->and($step('2026-11-13'))->toBe(['soon', 7])       // 7 days before
        ->and($step('2026-11-16'))->toBe(['soon', 7])       // still the 7-day step (sent once by its key)
        ->and($step('2026-11-18'))->toBe(['soon', 2])
        ->and($step('2026-11-20'))->toBe(['today', 0])
        ->and($step('2026-11-21'))->toBe(['overdue', 1])
        ->and($step('2026-11-24'))->toBe(['overdue', 2])
        ->and($step('2026-11-27'))->toBeNull()              // third would exceed max_after = 2
        ->and($step('2026-11-22', 3))->toBeNull()           // inside the 3 grace days
        ->and($step('2026-11-24', 3))->toBe(['overdue', 1]);
});

it('sends the policy reminders through the outbox once each, honouring grace days and the cap', function () {
    setPolicy($this->f);
    finAgree($this->f, '20000');
    $due = OrderDue::query()->where('order_id', $this->f->order->id)->where('reference', 'first')->sole();
    $due->forceFill(['due_event' => 'date', 'due_date' => CarbonImmutable::now()->addDays(7)->toDateString(), 'grace_days' => 1])->save();
    $reminders = app(CollectionReminders::class);
    $dueOn = $due->due_date->toDateString();

    expect($reminders->runDue(now()))->toBe(1)->and($reminders->runDue(now()))->toBe(0);   // 7 days before, once
    $this->travel(5)->days();
    expect($reminders->runDue(now()))->toBe(1);                                         // 2 days before
    $this->travel(2)->days();
    expect($reminders->runDue(now()))->toBe(1);                                         // on the due date
    $this->travel(1)->days();
    expect($reminders->runDue(now()))->toBe(0);                                         // grace day
    foreach ([1, 3, 3] as $days) {
        $this->travel($days)->days();
        $reminders->runDue(now());
    }
    expect(reminderKeys($due->id))->toBe(["soon:{$dueOn}:7", "soon:{$dueOn}:2", "today:{$dueOn}:0", "overdue:{$dueOn}:1", "overdue:{$dueOn}:2"]);

    // Paid: nothing more.
    finPay($this->f->finance, $this->f->order, '10000');
    $this->travel(30)->days();
    expect($reminders->runDue(now()))->toBe(0);
});

it('never reminds outside the reminder hours', function () {
    setPolicy($this->f);
    finAgree($this->f, '20000');
    WorkSettings::query()->update(['reminder_from' => '00:00', 'reminder_until' => '00:00']);
    $this->travel(1)->days();
    expect(app(CollectionReminders::class)->runDue(now()))->toBe(0);
});

it('escalates an overdue due to the members holding the chosen role, once, only when they may see money', function () {
    setPolicy($this->f, ['escalate_after_days' => 4, 'escalate_to' => 'finance']);
    finAgree($this->f, '20000');
    $reminders = app(CollectionReminders::class);
    $escalated = fn () => UserNotification::query()->where('type', 'finance.due_escalated')->get();

    $this->travel(3)->days();
    $reminders->runDue(now());
    expect($escalated())->toHaveCount(0);
    $this->travel(1)->days();
    $reminders->runDue(now());
    $reminders->runDue(now());
    expect($escalated()->pluck('user_id')->all())->toBe([$this->f->finance->id]);

    // To the order's responsible person — only when their grant shows the order's money (P-37).
    setPolicy($this->f, ['escalate_after_days' => 1, 'escalate_to' => 'order_assignee']);
    $order = $this->f->order->refresh();
    OrderDue::query()->where('order_id', $order->id)->where('reference', 'first')->update(['due_date' => now()->subDays(2)->toDateString(), 'due_event' => 'date']);
    $reminders->runDue(now());
    $assignee = $order->assignee;
    expect(UserNotification::query()->where('type', 'finance.due_escalated')->where('user_id', $assignee->id)->count())
        ->toBe(Access::can($assignee, Permission::P37, $order) ? 1 : 0);
});

it('validates the policy and lets only P-104 change it, audited', function () {
    expect(fn () => setPolicy($this->f, ['before_days' => [1, 2, 3, 4]]))->toThrow(ValidationException::class)
        ->and(fn () => setPolicy($this->f, ['max_after' => 7]))->toThrow(ValidationException::class)
        ->and(fn () => setPolicy($this->f, ['escalate_after_days' => 5, 'escalate_to' => 'nobody']))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateReminderSettings::class)->handle($this->f->manager, ReminderSettings::DEFAULTS, null))->toThrow(AuthorizationException::class);

    setPolicy($this->f, ['before_days' => [2, 7]]);
    expect(ReminderSettings::current()->before_days)->toBe([7, 2])
        ->and(AuditEvent::query()->where('action', 'reminder_settings.updated')->count())->toBe(1);

    $this->actingAs($this->f->owner)->get('/settings/reminders')->assertOk();
    $this->actingAs($this->f->sales)->get('/settings/reminders')->assertForbidden();
    $this->actingAs($this->f->manager)->put('/settings/reminders', ReminderSettings::DEFAULTS)->assertForbidden();
});

it('reminds of appointments with the configured lead time', function () {
    $this->travelTo(now()->setTimezone('Asia/Riyadh')->addDay()->setTime(9, 0)->utc());
    $riyadh = now()->setTimezone('Asia/Riyadh');
    $tech = $this->f->org->member('executor', [$this->f->org->a]);
    $a = propose($this->f->sales, $this->f->order, [$tech->id], $riyadh->copy()->addHours(40)->format('Y-m-d H:i'), $riyadh->copy()->addHours(41)->format('Y-m-d H:i'));
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $a, 'phone');

    expect(CustomerEvents::remindDue(now()))->toBe(0); // 24 h by default
    setPolicy($this->f, ['appointment_hours' => 48]);
    expect(CustomerEvents::remindDue(now()))->toBe(1)
        ->and(CustomerMessage::query()->where('event', 'measurement_reminder')->count())->toBe(1);
});
