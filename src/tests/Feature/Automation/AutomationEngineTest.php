<?php

use App\Actions\Rules\SaveAutomationRule;
use App\Actions\StateMachines\M03Interest\T00CreateInterest;
use App\Automation\AutomationEngine;
use App\Automation\AutomationTimers;
use App\Configuration\ConfigReleases;
use App\Models\AuditEvent;
use App\Models\Automation\AutomationActionLog;
use App\Models\Automation\AutomationEvent;
use App\Models\Automation\AutomationRun;
use App\Models\CustomerMessage;
use App\Models\Interest;
use App\Models\OrderNote;
use App\Models\OutboxMessage;
use App\Models\Task;
use App\Models\UserNotification;
use App\Models\WorkSettings;
use App\Workflow\StageEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AutomationTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

it('records nothing and acts on nothing while no release has rules; a drafted rule acts only once published', function () {
    autoRule($this->f->owner, 'approved_note', 'document.approved', [noteAction()]);
    finAgree($this->f, '10000');

    expect(AutomationEvent::query()->count())->toBe(0)->and(OrderNote::query()->count())->toBe(0);
});

it('matches its trigger only: a quote approval adds the note, other triggers do not run the rule', function () {
    autoRule($this->f->owner, 'approved_note', 'document.approved', [noteAction()]);
    autoRule($this->f->owner, 'lead_note', 'lead.created', [noteAction()]);
    publishRules($this->f->owner);

    finAgree($this->f, '10000');

    $note = OrderNote::query()->sole();
    expect($note->order_id)->toBe($this->f->order->id)
        ->and($note->body)->toBe('ملاحظة آلية على '.$this->f->order->number)
        ->and($note->source)->toBe('automation')
        ->and($note->rule_key)->toBe('approved_note')
        ->and(runsOf('approved_note'))->toBe(['matched'])
        ->and(runsOf('lead_note'))->toBe([])
        // quote.sent is not listened to by any rule: no event for it.
        ->and(AutomationEvent::query()->pluck('trigger')->all())->toBe(['document.approved'])
        ->and(AuditEvent::query()->where('action', 'automation.action_executed')->count())->toBe(1);
});

it('evaluates typed conditions on the subject: amount, kind, branch; a failed condition is logged as skipped', function () {
    autoRule($this->f->owner, 'big_quote', 'document.approved', [noteAction('كبير')], [
        ['field' => 'order.total', 'op' => 'gt', 'value' => 2000000],
        ['field' => 'document.kind', 'op' => 'eq', 'value' => 'quote'],
    ]);
    autoRule($this->f->owner, 'branch_b', 'document.approved', [noteAction('فرع ب')], [
        ['field' => 'branch', 'op' => 'eq', 'value' => (string) $this->f->org->b->id],
    ]);
    publishRules($this->f->owner);

    finAgree($this->f, '10000');

    expect(OrderNote::query()->count())->toBe(0)
        ->and(runsOf('big_quote'))->toBe(['skipped:conditions'])
        ->and(runsOf('branch_b'))->toBe(['skipped:conditions']);
    $detail = AutomationRun::query()->where('rule_key', 'big_quote')->sole()->detail;
    expect($detail['conditions'][0])->toMatchArray(['field' => 'order.total', 'actual' => 1000000, 'passed' => false])
        ->and($detail['conditions'][1]['passed'])->toBeTrue();
});

it('sends the customer message through the outbox template once, with the event and rule in its key', function () {
    autoRule($this->f->owner, 'thanks', 'document.approved', [['type' => 'send_message', 'template' => 'quote_ready', 'channel' => 'auto']]);
    publishRules($this->f->owner);

    finAgree($this->f, '10000');

    $message = CustomerMessage::query()->where('idempotency_key', 'like', 'automation:thanks:%')->sole();
    expect($message->customer_id)->toBe($this->f->order->customer_id)
        ->and($message->order_id)->toBe($this->f->order->id)
        ->and($message->body)->toContain($this->f->order->number)
        ->and(OutboxMessage::query()->where('idempotency_key', $message->idempotency_key.':sms')->exists())->toBeTrue();
});

it('creates a follow-up task with a working-day SLA routed like flow work, notifies role holders in scope only, and assigns a lead', function () {
    $managerA = $this->f->manager;
    $managerB = $this->f->org->member('manager', [$this->f->org->b]);
    autoRule($this->f->owner, 'follow', 'document.approved', [
        ['type' => 'create_task', 'title' => ['ar' => 'اتصل بالعميل {customer}', 'en' => null], 'sla_days' => 2, 'to' => ['type' => 'member', 'id' => $this->f->sales->id]],
        ['type' => 'notify', 'to' => ['type' => 'role', 'role' => 'manager'], 'message' => ['ar' => 'اعتُمد عرض {order}', 'en' => null]],
    ]);
    autoRule($this->f->owner, 'lead_owner', 'lead.created', [['type' => 'assign', 'to' => ['type' => 'member', 'id' => $this->f->sales->id]]]);
    publishRules($this->f->owner);

    finAgree($this->f, '10000');

    $task = Task::query()->where('kind', Task::KIND_AUTOMATION)->sole();
    expect($task->order_id)->toBe($this->f->order->id)
        ->and($task->assignee_id)->toBe($this->f->sales->id)
        ->and($task->title->ar)->toBe('اتصل بالعميل '.$this->f->order->customer->name)
        ->and($task->due_at)->not->toBeNull()
        ->and($task->due_at->greaterThan(now()->addDay()))->toBeTrue();
    // Tenancy/scope: the manager of branch B cannot open an order of branch A, so is not told about it.
    $notified = UserNotification::query()->where('type', 'automation.order')->pluck('user_id')->all();
    expect($notified)->toContain($managerA->id)->not->toContain($managerB->id)
        ->and(UserNotification::query()->where('type', 'automation.order')->first()->data['params']['message'])->toBe('اعتُمد عرض '.$this->f->order->number);

    $lead = app(T00CreateInterest::class)->handle($this->f->owner, ['name' => 'سارة القحطاني', 'mobile' => '0555551234', 'need' => 'مطبخ', 'channel' => 'website', 'branch_id' => $this->f->org->a->id]);
    expect($lead->refresh()->assignee_id)->toBe($this->f->sales->id)->and(runsOf('lead_owner'))->toBe(['matched']);
});

it('is idempotent per event, rule and action: processing again or after a crash never repeats an action', function () {
    autoRule($this->f->owner, 'twice', 'document.approved', [noteAction(), ['type' => 'send_message', 'template' => 'quote_ready', 'channel' => 'auto']]);
    publishRules($this->f->owner);
    finAgree($this->f, '10000');
    $event = AutomationEvent::query()->sole();

    // A lost job: the event looks unprocessed and the run failed half-way.
    $event->forceFill(['processed_at' => null, 'attempts' => 0])->save();
    AutomationRun::query()->update(['outcome' => AutomationRun::FAILED]);
    app(AutomationEngine::class)->process($event->id);
    app(AutomationEngine::class)->process($event->id);

    expect(OrderNote::query()->count())->toBe(1)
        ->and(CustomerMessage::query()->where('idempotency_key', 'like', 'automation:%')->count())->toBe(1)
        ->and(AutomationActionLog::query()->count())->toBe(2)
        ->and(runsOf('twice'))->toBe(['matched'])
        // The same source fact is one event.
        ->and(AutomationEvent::query()->count())->toBe(1);
});

it('stops loops: a rule whose action raises its own trigger does not run again, and the chain is recorded', function () {
    $task = ['type' => 'create_task', 'title' => ['ar' => 'مهمة متابعة', 'en' => null], 'sla_days' => 1, 'to' => ['type' => 'responsible']];
    autoRule($this->f->owner, 'on_approval', 'document.approved', [$task]);
    autoRule($this->f->owner, 'task_on_task', 'task.created', [$task]);
    publishRules($this->f->owner);

    finAgree($this->f, '10000');

    // on_approval → task 1 → task_on_task → task 2 → task_on_task refused (its own action caused the event).
    expect(Task::query()->where('kind', Task::KIND_AUTOMATION)->count())->toBe(2)
        ->and(runsOf('task_on_task'))->toBe(['matched', 'skipped:loop_self']);
    $chains = AutomationEvent::query()->where('trigger', 'task.created')->orderBy('id')->get(['caused_by', 'depth'])
        ->map(fn ($e) => [$e->caused_by, $e->depth])->all();
    expect($chains)->toBe([[['on_approval'], 1], [['on_approval', 'task_on_task'], 2]]);
});

it('limits how often one rule acts on one subject in a day', function () {
    autoRule($this->f->owner, 'noisy', 'task.created', [noteAction()]);
    publishRules($this->f->owner);
    $stage = app(StageEngine::class)->activeStage($this->f->order);
    for ($i = 0; $i < AutomationEngine::MAX_PER_SUBJECT_DAY + 2; $i++) {
        $event = AutomationEvent::query()->create([
            'trigger' => 'task.created', 'subject_type' => $this->f->order->getMorphClass(), 'subject_id' => $this->f->order->id,
            'order_id' => $this->f->order->id, 'source' => 'timer', 'dedupe_key' => "test:{$i}", 'depth' => 0, 'occurred_at' => now(),
        ]);
        app(AutomationEngine::class)->process($event->id);
    }

    expect(array_count_values(runsOf('noisy')))->toMatchArray(['matched' => AutomationEngine::MAX_PER_SUBJECT_DAY, 'skipped:rate_limit' => 2]);
});

it('stops a rule at once with the kill switch, in every release, until revived; both are audited', function () {
    $rule = autoRule($this->f->owner, 'stop_me', 'document.approved', [noteAction()]);
    publishRules($this->f->owner);
    app(SaveAutomationRule::class)->kill($this->f->owner, $rule, 'رسائل خاطئة');

    finAgree($this->f, '10000');

    expect(OrderNote::query()->count())->toBe(0)->and(runsOf('stop_me'))->toBe(['skipped:killed'])
        ->and(AuditEvent::query()->where('action', 'automation_rule.killed')->value('reason'))->toBe('رسائل خاطئة');

    app(SaveAutomationRule::class)->revive($this->f->owner, $rule->refresh());
    expect(AuditEvent::query()->where('action', 'automation_rule.revived')->exists())->toBeTrue()
        ->and($rule->refresh()->killed_at)->toBeNull();
});

it('runs an order on the rules of the release it pinned; new orders follow the newer release', function () {
    $rule = autoRule($this->f->owner, 'old_rule', 'document.approved', [noteAction('قديمة')]);
    publishRules($this->f->owner);
    // An order opened under release 1 pins it.
    $this->f->order = openCustomOrder($this->f->sales, $this->f->inA);
    finToAgreementStage($this->f);
    $pinned = $this->f->order->refresh();
    expect($pinned->config_release_id)->toBe(ConfigReleases::currentId());

    app(SaveAutomationRule::class)->archive($this->f->owner, $rule);
    autoRule($this->f->owner, 'new_rule', 'document.approved', [noteAction('جديدة')]);
    publishRules($this->f->owner);

    finAgree($this->f, '10000');
    expect(OrderNote::query()->where('order_id', $pinned->id)->pluck('body')->all())->toBe(['قديمة']);

    $g = clone $this->f;
    $g->order = openCustomOrder($this->f->sales, $this->f->inA);
    finToAgreementStage($g);
    finAgree($g, '5000');
    expect(OrderNote::query()->where('order_id', $g->order->id)->pluck('body')->all())->toBe(['جديدة']);
});

it('raises timer events once per subject and anchor: an overdue due creates one follow-up per due date', function () {
    // No settings row exists yet, so an update would touch nothing and leave the 09:00–20:00 default window in force.
    $this->travelTo(CarbonImmutable::parse('2026-11-08T09:00:00Z')); // Sunday noon in Riyadh, a working day
    WorkSettings::query()->create([...WorkSettings::DEFAULTS, 'reminder_from' => '00:00', 'reminder_until' => '23:59']);
    autoRule($this->f->owner, 'late', 'payment.overdue', [noteAction('دفعة متأخرة')], [['field' => 'due.amount', 'op' => 'gt', 'value' => 0]], ['days' => 3]);
    publishRules($this->f->owner);
    finAgree($this->f, '10000');

    $this->travel(2)->days();
    expect(app(AutomationTimers::class)->tick(now()))->toBe(0);
    $this->travel(3)->days();
    expect(app(AutomationTimers::class)->tick(now()))->toBe(1)
        ->and(app(AutomationTimers::class)->tick(now()))->toBe(0);

    expect(OrderNote::query()->pluck('body')->all())->toBe(['دفعة متأخرة'])
        ->and(AutomationEvent::query()->sole()->context['param'])->toBe(3);
});

it('alerts about a lead nobody touched for the configured hours', function () {
    // No settings row exists yet, so an update would touch nothing and leave the 09:00–20:00 default window in force.
    $this->travelTo(CarbonImmutable::parse('2026-11-08T09:00:00Z')); // Sunday noon in Riyadh, a working day
    WorkSettings::query()->create([...WorkSettings::DEFAULTS, 'reminder_from' => '00:00', 'reminder_until' => '23:59']);
    autoRule($this->f->owner, 'idle_lead', 'lead.stale', [['type' => 'notify', 'to' => ['type' => 'role', 'role' => 'manager'], 'message' => ['ar' => 'العميل {subject} بلا متابعة', 'en' => null]]],
        [['field' => 'lead.open', 'op' => 'is_true', 'value' => null]], ['hours' => 48]);
    publishRules($this->f->owner);
    $lead = app(T00CreateInterest::class)->handle($this->f->owner, ['name' => 'نورة الشهري', 'mobile' => '0555557777', 'need' => 'خزائن', 'channel' => 'phone', 'branch_id' => $this->f->org->a->id]);

    $this->travel(49)->hours();
    app(AutomationTimers::class)->tick(now());

    $notice = UserNotification::query()->where('type', 'automation.lead')->sole();
    expect($notice->user_id)->toBe($this->f->manager->id)
        ->and($notice->target_id)->toBe($lead->id)
        ->and($notice->data['params']['message'])->toBe('العميل '.Interest::query()->find($lead->id)->reference.' بلا متابعة');
});
