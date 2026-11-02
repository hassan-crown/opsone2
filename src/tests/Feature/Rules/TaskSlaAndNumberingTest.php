<?php

/**
 * Task SLA in working days and document numbering formats (ADR-0037): due times skip rest days and holidays and are
 * fixed at creation; `work.reminder` and the escalation go out once each inside the reminder hours; numbers follow
 * the facility's prefix, Gregorian/Hijri/no year, branch segment and starting number while staying gapless and
 * unique; tax invoice sequences are never configurable. P-103 only.
 */

use App\Actions\Rules\SaveNumberFormat;
use App\Actions\Rules\UpdateTaskSla;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Task;
use App\Models\UserNotification;
use App\Models\WorkSettings;
use App\Numbering\NumberFormats;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use App\Tasks\TaskSla;
use App\Tasks\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/RulesTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function setSla(object $f, array $overrides = []): void
{
    app(UpdateTaskSla::class)->handle($f->owner, [
        'enabled' => true, 'default_days' => 2, 'by_type' => [], 'holidays' => [], 'escalate_after_days' => 1, ...$overrides,
    ]);
}

function takeNumber(string $sequence, string $prefix, int $pad = 5, ?int $branchId = null): string
{
    return LockingTransaction::run(fn () => app(GaplessSequence::class)->format($sequence, $prefix, $pad, $branchId));
}

it('counts working days: rest days and holidays are skipped, the due time is the end of the working day', function () {
    setSla($this->f, ['holidays' => ['2026-11-09']]);
    $days = WorkingDays::current();
    $thursday = CarbonImmutable::parse('2026-11-05 10:00', 'Asia/Riyadh');

    // Thu + 2 working days: Fri/Sat off, Sun the 8th, Mon the 9th is a holiday → Tue the 10th at 17:00 Riyadh.
    expect($days->dueAt($thursday, 2)->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'))->toBe('2026-11-10 17:00')
        ->and($days->dueAt($thursday, 0)->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'))->toBe('2026-11-05 17:00')
        ->and($days->dueAt(CarbonImmutable::parse('2026-11-06 10:00', 'Asia/Riyadh'), 0)->setTimezone('Asia/Riyadh')->format('Y-m-d'))->toBe('2026-11-08')
        ->and($days->between($thursday, CarbonImmutable::parse('2026-11-11 12:00', 'Asia/Riyadh')))->toBe(3);
});

it('fills due_at on new tasks from the action type, keeps it when the setting changes, and can be switched off', function () {
    setSla($this->f, ['by_type' => ['capture_needs' => 1]]);
    $order = openCustomOrder($this->f->sales, $this->f->inA);
    $task = Task::query()->where('order_id', $order->id)->orderBy('id')->firstOrFail();
    expect($task->due_at)->not->toBeNull()
        ->and($task->due_at->getTimestamp())->toBe(TaskSla::dueAt('capture_needs', $task->created_at)->getTimestamp());

    $before = $task->due_at->getTimestamp();
    setSla($this->f, ['default_days' => 9, 'by_type' => ['capture_needs' => 9]]);
    expect($task->refresh()->due_at->getTimestamp())->toBe($before);

    setSla($this->f, ['enabled' => false]);
    $later = openCustomOrder($this->f->sales, $this->f->inA);
    expect(Task::query()->where('order_id', $later->id)->whereNotNull('due_at')->count())->toBe(0);
});

it('sends work.reminder on the due day and escalates a late task once, inside the reminder hours', function () {
    WorkSettings::query()->create([...WorkSettings::DEFAULTS, 'reminder_from' => '00:00', 'reminder_until' => '23:59']);
    $task = Task::query()->where('order_id', $this->f->order->id)->whereNotNull('assignee_id')->orderBy('id')->firstOrFail();
    $task->forceFill(['due_at' => now()->addHours(3)])->save();
    $sla = app(TaskSla::class);

    expect($sla->run(now()))->toBe(1)->and($sla->run(now()))->toBe(0);
    expect(UserNotification::query()->where('type', 'work.reminder')->where('user_id', $task->assignee_id)->count())->toBe(1);

    // Three working days late (the escalation threshold is one): the follower — else the owner — is told once.
    $this->travelTo(WorkingDays::current()->dueAt(now(), 3)->addHour());
    $sla->run(now());
    $sla->run(now());
    $escalated = UserNotification::query()->where('type', 'work.escalated')->get();
    expect($escalated)->toHaveCount(1)->and($escalated[0]->user_id)->not->toBe($task->assignee_id);

    WorkSettings::query()->update(['reminder_from' => '00:00', 'reminder_until' => '00:00']);
    $task->forceFill(['due_at' => now()->addHour()])->save();
    expect($sla->run(now()))->toBe(0);
});

it('validates SLA settings and lets only P-103 change them, audited', function () {
    expect(fn () => setSla($this->f, ['holidays' => ['05/11/2026']]))->toThrow(ValidationException::class)
        ->and(fn () => setSla($this->f, ['default_days' => 61]))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateTaskSla::class)->handle($this->f->manager, ['enabled' => true, 'default_days' => 1, 'by_type' => [], 'holidays' => [], 'escalate_after_days' => 1]))
        ->toThrow(AuthorizationException::class);
    setSla($this->f, ['by_type' => ['work_task' => 5, 'free_script' => 2], 'holidays' => ['2026-12-02', '2026-09-23']]);
    $settings = WorkSettings::current();
    expect($settings->task_sla['by_type'])->toBe(['work_task' => 5])
        ->and($settings->holidays)->toBe(['2026-09-23', '2026-12-02'])
        ->and(AuditEvent::query()->where('action', 'task_sla.updated')->count())->toBe(1);
    $this->actingAs($this->f->owner)->get('/settings/task-sla')->assertOk();
    $this->actingAs($this->f->manager)->put('/settings/task-sla', ['enabled' => true, 'default_days' => 1, 'by_type' => [], 'holidays' => [], 'escalate_after_days' => 1])->assertForbidden();
});

it('formats numbers with the facility prefix and a Hijri or no year, gapless and from a starting number', function () {
    $save = app(SaveNumberFormat::class);
    expect(takeNumber('receipt', 'RC', 6))->toBe('RC-'.now()->format('Y').'-000001');

    $save->handle($this->f->owner, ['sequence' => 'receipt', 'prefix' => 'SND', 'year_mode' => 'hijri', 'per_branch' => false, 'pad' => 4, 'start_at' => 3500]);
    $hijri = NumberFormats::hijriYear(now('Asia/Riyadh'));
    expect($hijri)->toBeGreaterThan(1440)->toBeLessThan(1500)
        ->and(takeNumber('receipt', 'RC', 6))->toBe("SND-{$hijri}-3500")
        ->and(takeNumber('receipt', 'RC', 6))->toBe("SND-{$hijri}-3501");

    // A start at or below the last number of the period is refused (numbers never repeat).
    expect(fn () => $save->handle($this->f->owner, ['sequence' => 'receipt', 'prefix' => 'SND', 'year_mode' => 'hijri', 'per_branch' => false, 'pad' => 4, 'start_at' => 3501]))
        ->toThrow(ValidationException::class);

    $save->handle($this->f->owner, ['sequence' => 'refund', 'prefix' => 'RFD', 'year_mode' => 'none', 'per_branch' => false, 'pad' => 3, 'start_at' => null]);
    expect(takeNumber('refund', 'RF', 6))->toBe('RFD-001')->and(takeNumber('refund', 'RF', 6))->toBe('RFD-002');

    // A rolled-back number is given back: still gapless.
    try {
        LockingTransaction::run(function () {
            app(GaplessSequence::class)->format('refund', 'RF', 6);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(takeNumber('refund', 'RF', 6))->toBe('RFD-003');
});

it('puts the branch code in order numbers with a counter per branch', function () {
    Branch::query()->whereKey($this->f->org->a->id)->update(['code' => 'ryd']);
    app(SaveNumberFormat::class)->handle($this->f->owner, ['sequence' => 'orders', 'prefix' => 'KIT', 'year_mode' => 'gregorian', 'per_branch' => true, 'pad' => 5, 'start_at' => null]);
    $year = now('Asia/Riyadh')->format('Y');

    $first = openCustomOrder($this->f->sales, $this->f->inA);
    $second = openCustomOrder($this->f->sales, $this->f->inA);
    expect([$first->number, $second->number])->toBe(["KIT-RYD-{$year}-00001", "KIT-RYD-{$year}-00002"])
        ->and(Order::query()->distinct()->count('number'))->toBe(Order::query()->count());
});

it('never formats tax invoices, refuses a taken or reserved prefix and a branch segment elsewhere, and needs P-103', function () {
    $save = app(SaveNumberFormat::class);
    $format = fn (array $data) => $save->handle($this->f->owner, ['prefix' => 'ABC', 'year_mode' => 'gregorian', 'per_branch' => false, 'pad' => 5, 'start_at' => null, ...$data]);

    expect(fn () => $format(['sequence' => 'e_invoice']))->toThrow(ValidationException::class)
        ->and(fn () => $format(['sequence' => 'receipt', 'prefix' => 'INV']))->toThrow(ValidationException::class)
        ->and(fn () => $format(['sequence' => 'receipt', 'prefix' => 'ORD']))->toThrow(ValidationException::class)
        ->and(fn () => $format(['sequence' => 'receipt', 'prefix' => 'rc']))->toThrow(ValidationException::class)
        ->and(fn () => $format(['sequence' => 'receipt', 'per_branch' => true]))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($this->f->manager, ['sequence' => 'receipt', 'prefix' => 'ABC', 'year_mode' => 'none', 'per_branch' => false, 'pad' => 5, 'start_at' => null]))
        ->toThrow(AuthorizationException::class);
    expect(NumberFormats::find('e_invoice'))->toBeNull()
        ->and(takeNumber('e_invoice', 'INV', 6))->toBe('INV-'.now()->format('Y').'-000001');

    $format(['sequence' => 'receipt']);
    expect(AuditEvent::query()->where('action', 'number_format.saved')->count())->toBe(1);
    $this->actingAs($this->f->owner)->get('/settings/numbering')->assertOk()->assertInertia(fn ($p) => $p->component('rules/numbering')->has('formats', count(NumberFormats::SEQUENCES)));
    $this->actingAs($this->f->sales)->get('/settings/numbering')->assertForbidden();
});
