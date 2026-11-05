<?php

namespace App\Automation;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\MembershipStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Messages\MessageTemplates;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\Finance\OrderDue;
use App\Models\Interest;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderStage;
use App\Models\RoleTemplate;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Notifier;
use App\Notifications\Outbox;
use App\Orders\OrderAccess;
use App\Tasks\WorkingDays;
use App\Whatsapp\CustomerMessenger;
use App\Whatsapp\WhatsAppEvent;
use App\Workflow\AppointmentBooking;
use App\Workflow\StageAssignment;
use App\Workflow\StageEngine;
use App\Workflow\TaskEligibility;
use App\Workflow\TaskFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The closed action catalogue (ADR-0042, analysis §4.1): message the customer through the outbox templates, create a
 * task with an SLA, notify people, assign the subject, add an order note. Nothing here records money, moves stock,
 * changes a price or closes an order — those stay with people. Every action runs as «the system on behalf of the
 * rule» (no actor, source `automation`) through the existing write paths: CustomerMessenger / Outbox, Notifier,
 * TaskFactory routing and eligibility, the order and lead assignment checks.
 *
 * `describe()` is pure (the dry run and the log); `execute()` writes, inside the engine's transaction, and is called
 * at most once per rule, event and action index (the engine's idempotency key, passed here as `$key`).
 */
final class AutomationActions
{
    public const TYPES = ['send_message', 'create_task', 'notify', 'assign', 'add_note'];

    public const MAX_ACTIONS = 5;

    public const MAX_RECIPIENTS = 20;

    /** Placeholders allowed in the rule's own texts (note, notice, task title). */
    public const VARIABLES = ['order', 'customer', 'subject', 'rule'];

    public const TARGETS = ['responsible', 'member', 'role'];

    public function __construct(
        private readonly CustomerMessenger $messenger,
        private readonly Outbox $outbox,
        private readonly Notifier $notifier,
        private readonly TransitionRecorder $transitions,
        private readonly TaskFactory $tasks,
    ) {}

    /**
     * Validates and normalizes one action's configuration.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public static function normalize(array $action, string $path): array
    {
        $type = $action['type'] ?? null;
        $fail = fn (string $field, string $key = 'automation.validation.action_value') => throw ValidationException::withMessages(["{$path}.{$field}" => __($key)]);
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            $fail('type', 'automation.validation.action_type');
        }

        return match ($type) {
            'send_message' => [
                'type' => $type,
                'template' => in_array($action['template'] ?? null, WhatsAppEvent::values(), true) ? $action['template'] : $fail('template'),
                'channel' => in_array($action['channel'] ?? 'auto', ['auto', 'mail'], true) ? ($action['channel'] ?? 'auto') : $fail('channel'),
            ],
            'create_task' => [
                'type' => $type,
                'title' => self::text($action['title'] ?? null, 120, "{$path}.title"),
                'sla_days' => is_numeric($action['sla_days'] ?? null) && (int) $action['sla_days'] >= 0 && (int) $action['sla_days'] <= 30 ? (int) $action['sla_days'] : $fail('sla_days'),
                'to' => self::target($action['to'] ?? ['type' => 'responsible'], "{$path}.to"),
            ],
            'notify' => [
                'type' => $type,
                'to' => self::target($action['to'] ?? null, "{$path}.to"),
                'message' => self::text($action['message'] ?? null, 300, "{$path}.message"),
            ],
            'assign' => [
                'type' => $type,
                'to' => ($t = self::target($action['to'] ?? null, "{$path}.to"))['type'] === 'responsible' ? $fail('to') : $t,
            ],
            'add_note' => [
                'type' => $type,
                'body' => self::text($action['body'] ?? null, 1000, "{$path}.body"),
            ],
        };
    }

    /**
     * @return array{ar: string, en: string|null}
     */
    private static function text(mixed $value, int $max, string $path): array
    {
        $ar = is_array($value) && is_string($value['ar'] ?? null) ? trim($value['ar']) : '';
        $en = is_array($value) && is_string($value['en'] ?? null) && trim($value['en']) !== '' ? trim($value['en']) : null;
        if ($ar === '' || mb_strlen($ar) > $max || ($en !== null && mb_strlen($en) > $max)) {
            throw ValidationException::withMessages(["{$path}.ar" => __('automation.validation.text', ['max' => $max])]);
        }
        MessageTemplates::checkVariables($ar.' '.$en, self::VARIABLES, "{$path}.ar");

        return ['ar' => $ar, 'en' => $en];
    }

    /**
     * `{type: responsible}`, `{type: member, id}` (an active member) or `{type: role, role}` (a role template key).
     *
     * @return array<string, mixed>
     */
    private static function target(mixed $to, string $path): array
    {
        $type = is_array($to) ? ($to['type'] ?? null) : null;
        if (! in_array($type, self::TARGETS, true)) {
            throw ValidationException::withMessages(["{$path}.type" => __('automation.validation.target')]);
        }
        if ($type === 'member') {
            $id = (int) ($to['id'] ?? 0);
            if (! Membership::query()->where('user_id', $id)->where('status', MembershipStatus::Active)->exists()) {
                throw ValidationException::withMessages(["{$path}.id" => __('automation.validation.member')]);
            }

            return ['type' => $type, 'id' => $id];
        }
        if ($type === 'role') {
            $role = is_string($to['role'] ?? null) ? $to['role'] : '';
            if (! RoleTemplate::query()->where('key', $role)->exists()) {
                throw ValidationException::withMessages(["{$path}.role" => __('automation.validation.role')]);
            }

            return ['type' => $type, 'role' => $role];
        }

        return ['type' => $type];
    }

    /**
     * What the action would do for this subject, without writing: the rendered texts, the people, or why it would be
     * skipped. Used by the dry run, and stored in the log of a run.
     *
     * @param  array<string, mixed>  $action
     * @return array{type: string, would: bool, reason: string|null, summary: array<string, mixed>}
     */
    public function describe(array $action, AutomationSubject $s, string $ruleName): array
    {
        $vars = $this->vars($s, $ruleName);

        return match ((string) $action['type']) {
            'send_message' => $this->describeMessage($action, $s),
            'create_task' => $this->describeTask($action, $s, $vars),
            'notify' => ($people = $this->recipients($action['to'], $s)) === []
                ? self::skip('notify', 'no_recipient')
                : self::would('notify', ['to' => array_map(fn (User $u) => $u->name, $people), 'message' => $this->render($action['message'], $vars)]),
            'assign' => ($id = $this->assignee($action['to'], $s)) === null
                ? self::skip('assign', $this->assignable($s) ? 'no_eligible' : 'unsupported_subject')
                : self::would('assign', ['to' => User::query()->whereKey($id)->value('name')]),
            'add_note' => $s->order === null
                ? self::skip('add_note', 'no_order')
                : self::would('add_note', ['order' => $s->order->number, 'body' => $this->render($action['body'], $vars)]),
            default => self::skip((string) $action['type'], 'unsupported_subject'),
        };
    }

    /**
     * Performs the action. Returns what happened (stored on the action log); a skipped action writes nothing.
     *
     * @param  array<string, mixed>  $action
     * @return array{status: string, reason: string|null, summary: array<string, mixed>}
     */
    public function execute(array $action, AutomationSubject $s, string $ruleKey, string $ruleName, string $key): array
    {
        $plan = $this->describe($action, $s, $ruleName);
        if (! $plan['would']) {
            return ['status' => 'skipped', 'reason' => $plan['reason'], 'summary' => $plan['summary']];
        }
        $vars = $this->vars($s, $ruleName);
        $summary = match ((string) $action['type']) {
            'send_message' => $this->sendMessage($action, $s, $key),
            'create_task' => $this->createTask($action, $s, $vars, $ruleKey),
            'notify' => $this->notify($action, $s, $vars, $key),
            'assign' => $this->assign($action, $s, $ruleKey),
            'add_note' => $this->addNote($action, $s, $vars, $ruleKey),
            default => [],
        };
        Audit::record(AuditAction::AutomationActionExecuted, $s->order ?? $s->record, null,
            after: ['rule' => $ruleKey, 'action' => $action['type'], 'event' => $s->event->id, ...array_intersect_key($summary, ['task' => 1, 'assignee' => 1, 'note' => 1, 'recipients' => 1, 'channel' => 1])],
            source: TransitionRecorder::SOURCE_AUTOMATION, label: $ruleKey);

        return ['status' => 'done', 'reason' => null, 'summary' => $summary];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{type: string, would: bool, reason: string|null, summary: array<string, mixed>}
     */
    private static function would(string $type, array $summary): array
    {
        return ['type' => $type, 'would' => true, 'reason' => null, 'summary' => $summary];
    }

    /**
     * @return array{type: string, would: bool, reason: string|null, summary: array<string, mixed>}
     */
    private static function skip(string $type, string $reason): array
    {
        return ['type' => $type, 'would' => false, 'reason' => $reason, 'summary' => []];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{type: string, would: bool, reason: string|null, summary: array<string, mixed>}
     */
    private function describeMessage(array $action, AutomationSubject $s): array
    {
        $customer = $s->customer;
        if ($customer === null) {
            return self::skip('send_message', 'no_customer');
        }
        $event = WhatsAppEvent::from($action['template']);
        $locale = CustomerMessenger::locale($customer);
        $params = ['name' => $customer->name, 'facility' => CustomerMessenger::facilityName($locale), ...$this->messageParams($s, $locale)];
        $text = MessageTemplates::body('customer.'.$event->value, 'sms', $locale, $params, $event->textKey());
        if ($action['channel'] === 'mail') {
            return trim((string) $customer->email) === ''
                ? self::skip('send_message', 'no_email')
                : self::would('send_message', ['channel' => 'mail', 'to' => $customer->name, 'text' => $text]);
        }
        if (trim($customer->mobile) === '') {
            return self::skip('send_message', 'no_mobile');
        }

        return self::would('send_message', ['channel' => $this->messenger->usesWhatsApp($customer, $event) ? 'whatsapp' : 'sms', 'to' => $customer->name, 'template' => $event->value, 'text' => $text]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, string>  $vars
     * @return array{type: string, would: bool, reason: string|null, summary: array<string, mixed>}
     */
    private function describeTask(array $action, AutomationSubject $s, array $vars): array
    {
        if ($s->order === null) {
            return self::skip('create_task', 'no_order');
        }
        if ($this->taskStage($s->order) === null) {
            return self::skip('create_task', 'no_stage');
        }
        $assignee = $this->taskAssignee($action['to'], $s);

        return self::would('create_task', [
            'title' => $this->render($action['title'], $vars),
            'due_at' => WorkingDays::current()->dueAt(now(), (int) $action['sla_days'])->setTimezone(AppointmentBooking::timezone())->format('Y-m-d H:i'),
            'to' => $assignee === null ? null : User::query()->whereKey($assignee)->value('name'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function sendMessage(array $action, AutomationSubject $s, string $key): array
    {
        $customer = $s->customer;
        assert($customer !== null);
        $event = WhatsAppEvent::from($action['template']);
        $locale = CustomerMessenger::locale($customer);
        $params = $this->messageParams($s, $locale);
        if ($action['channel'] === 'mail') {
            $all = ['name' => $customer->name, 'facility' => CustomerMessenger::facilityName($locale), ...$params];
            $text = MessageTemplates::body('customer.'.$event->value, 'sms', $locale, $all, $event->textKey());
            $this->outbox->enqueueMail((string) $customer->email, CustomerMessenger::facilityName($locale), [$text], null, $locale, "{$key}:mail");

            return ['channel' => 'mail', 'template' => $event->value];
        }
        $message = $this->messenger->send($customer, $event, $params, $key, $s->order);

        return ['channel' => $message->channel ?? null, 'template' => $event->value, 'message' => $message?->id];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    private function createTask(array $action, AutomationSubject $s, array $vars, string $ruleKey): array
    {
        $order = $s->order;
        $stage = $order === null ? null : $this->taskStage($order);
        assert($order !== null && $stage !== null);
        $task = new Task([
            'order_id' => $order->id,
            'order_stage_id' => $stage->id,
            'order_action_id' => null,
            'kind' => Task::KIND_AUTOMATION,
            'title' => ['ar' => $this->render($action['title'], $vars, 'ar'), 'en' => $action['title']['en'] === null ? null : $this->render($action['title'], $vars, 'en')],
            'follow_up_user_id' => TaskEligibility::followerOf($order),
            'status' => TaskStatus::Unassigned,
            // Fixed at creation in working days, like every task SLA (ADR-0037).
            'due_at' => WorkingDays::current()->dueAt(now(), (int) $action['sla_days']),
        ]);
        $task->setRelation('order', $order);
        $assignee = $this->taskAssignee($action['to'], $s, $task);
        if ($assignee !== null) {
            $task->assignee_id = $assignee;
            $task->status = TaskStatus::Assigned;
            $task->assigned_at = now();
        }
        $task->save();
        $this->transitions->record(StateMachine::M07, 'T00', $task, null, $task->status, null, TransitionRecorder::SOURCE_AUTOMATION, null, [
            'order_id' => $order->id, 'kind' => Task::KIND_AUTOMATION, 'rule' => $ruleKey,
        ]);
        $this->tasks->announce($task, $order);

        return ['task' => $task->id, 'assignee' => $assignee];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    private function notify(array $action, AutomationSubject $s, array $vars, string $key): array
    {
        [$type, $target] = $this->noticeTarget($s);
        $sent = [];
        foreach ($this->recipients($action['to'], $s) as $user) {
            $locale = $user->locale ?? 'ar';
            $params = ['rule' => $vars['rule'], 'subject' => $vars['subject'], 'message' => $this->render($action['message'], $vars, $locale)];
            if ($this->notifier->send($user, $type, $params, $target, "{$key}:{$user->id}") !== null) {
                $sent[] = $user->id;
            }
        }

        return ['recipients' => $sent];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function assign(array $action, AutomationSubject $s, string $ruleKey): array
    {
        $id = $this->assignee($action['to'], $s);
        assert($id !== null);
        $record = $s->record;
        if ($record instanceof Task) {
            $locked = Task::query()->lockForUpdate()->findOrFail($record->id);
            $from = $locked->status;
            $locked->forceFill(['assignee_id' => $id, 'status' => TaskStatus::Assigned, 'assigned_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M07, $from === TaskStatus::Unassigned ? 'T01' : 'T06', $locked, $from, TaskStatus::Assigned, null, TransitionRecorder::SOURCE_AUTOMATION, null, ['rule' => $ruleKey]);
            Audit::record(AuditAction::TaskAssigned, $locked, null, before: ['assignee_id' => $record->assignee_id], after: ['assignee_id' => $id], source: TransitionRecorder::SOURCE_AUTOMATION);
            $this->tasks->announce($locked, $locked->order);

            return ['assignee' => $id, 'task' => $locked->id];
        }
        if ($record instanceof Interest) {
            $locked = Interest::query()->lockForUpdate()->findOrFail($record->id);
            $before = $locked->assignee_id;
            $locked->forceFill(['assignee_id' => $id])->save();
            Audit::record(AuditAction::InterestUpdated, $locked, null, before: ['assignee_id' => $before], after: ['assignee_id' => $id], source: TransitionRecorder::SOURCE_AUTOMATION);

            return ['assignee' => $id];
        }
        $order = Order::query()->lockForUpdate()->findOrFail($s->order?->id);
        $before = $order->assignee_id;
        $order->forceFill(['assignee_id' => $id, 'lock_version' => $order->lock_version + 1])->save();
        Audit::record(AuditAction::OrderUpdated, $order, null, before: ['assignee_id' => $before], after: ['assignee_id' => $id], source: TransitionRecorder::SOURCE_AUTOMATION);

        return ['assignee' => $id];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    private function addNote(array $action, AutomationSubject $s, array $vars, string $ruleKey): array
    {
        $note = OrderNote::query()->create([
            'order_id' => $s->order?->id, 'body' => $this->render($action['body'], $vars), 'source' => TransitionRecorder::SOURCE_AUTOMATION,
            'rule_key' => $ruleKey, 'created_by' => null, 'created_at' => now(),
        ]);

        return ['note' => $note->id];
    }

    /**
     * Who a target names for this subject: the responsible person, a member, or the active holders of a role who may
     * open the subject (its branch scope), at most MAX_RECIPIENTS.
     *
     * @param  array<string, mixed>  $to
     * @return list<User>
     */
    public function recipients(array $to, AutomationSubject $s): array
    {
        $ids = match ($to['type']) {
            'responsible' => array_filter([$s->responsibleId()]),
            'member' => [(int) $to['id']],
            'role' => MembershipRole::query()->whereIn('role_template_id', RoleTemplate::query()->where('key', $to['role'])->select('id'))
                ->join('memberships', 'memberships.id', '=', 'membership_roles.membership_id')
                ->where('memberships.status', MembershipStatus::Active->value)
                ->orderBy('memberships.user_id')->pluck('memberships.user_id')->map(fn ($id) => (int) $id)->unique()->all(),
            default => [],
        };
        [, $target, $permission] = $this->noticeTarget($s);
        $users = User::query()->whereIn('id', $ids)->where('is_support', false)
            ->whereIn('id', Membership::query()->where('status', MembershipStatus::Active)->select('user_id'))->orderBy('id')->get();

        return array_values($users->filter(fn (User $u) => $target === null || $permission === null || Access::can($u, $permission, $target))
            ->take(self::MAX_RECIPIENTS)->all());
    }

    /**
     * The notification type, its target record and the permission opening it re-checks.
     *
     * @return array{0: string, 1: Model|null, 2: Permission|null}
     */
    private function noticeTarget(AutomationSubject $s): array
    {
        return match (true) {
            $s->record instanceof Task => ['automation.task', $s->record, Permission::P24],
            $s->order !== null => ['automation.order', $s->order, Permission::P16],
            $s->record instanceof Interest => ['automation.lead', $s->record, Permission::P09],
            $s->record instanceof AppointmentRequest => ['automation.request', $s->record, Permission::P54],
            default => ['automation.notice', null, null],
        };
    }

    private function assignable(AutomationSubject $s): bool
    {
        return $s->record instanceof Task || $s->record instanceof Interest || ($s->order !== null && ! $s->record instanceof AppointmentRequest);
    }

    /**
     * The person an `assign` would give the subject to, checked as the manual paths check: a task's eligibility, a
     * lead's P-09 and an order's valid assignee (P-16 over its branch).
     *
     * @param  array<string, mixed>  $to
     */
    private function assignee(array $to, AutomationSubject $s): ?int
    {
        if (! $this->assignable($s)) {
            return null;
        }
        $record = $s->record;
        $ok = match (true) {
            $record instanceof Task => fn (int $id) => TaskEligibility::allows($id, $record),
            $record instanceof Interest => fn (int $id) => ($u = User::query()->find($id)) !== null && ! $u->is_support && Access::can($u, Permission::P09, $record),
            default => fn (int $id) => $s->order !== null && OrderAccess::isValidAssignee($id, $s->order),
        };
        $candidates = $to['type'] === 'member' ? [(int) $to['id']] : array_map(fn (User $u) => $u->id, $this->recipients($to, $s));
        foreach ($candidates as $id) {
            if ($id !== $s->responsibleId() && $ok($id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $to
     */
    private function taskAssignee(array $to, AutomationSubject $s, ?Task $task = null): ?int
    {
        $order = $s->order;
        assert($order !== null);
        $target = match ($to['type']) {
            'member' => ['type' => StageAssignment::MEMBER, 'id' => $to['id']],
            'role' => ['type' => StageAssignment::ROLE, 'id' => (int) RoleTemplate::query()->where('key', $to['role'])->value('id')],
            default => ['type' => StageAssignment::ORDER_ASSIGNEE],
        };

        return StageAssignment::resolve($target, $order, $this->taskStage($order), $task);
    }

    /** A follow-up task sits on the order's active stage, else its last one. */
    private function taskStage(Order $order): ?OrderStage
    {
        return app(StageEngine::class)->activeStage($order)
            ?? OrderStage::query()->where('order_id', $order->id)->orderByDesc('position')->first();
    }

    /**
     * Values of the customer template's parameters for this subject.
     *
     * @return array<string, string>
     */
    private function messageParams(AutomationSubject $s, string $locale): array
    {
        $params = ['order' => (string) ($s->order->number ?? ''), 'link' => route('customer.home'), 'when' => '', 'amount' => '', 'due' => '', 'number' => ''];
        if ($s->record instanceof Appointment) {
            $params['when'] = $s->record->starts_at->setTimezone(AppointmentBooking::timezone())->format('Y-m-d H:i');
        }
        if ($s->record instanceof OrderDue) {
            $row = $s->due();
            $params['amount'] = number_format(($row['remaining'] ?? 0) / 100, 2);
            $params['due'] = (string) $s->record->name->get($locale);
        }

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function vars(AutomationSubject $s, string $ruleName): array
    {
        return ['order' => (string) ($s->order->number ?? ''), 'customer' => (string) ($s->customer->name ?? ''), 'subject' => $s->label(), 'rule' => $ruleName];
    }

    /**
     * @param  array{ar: string, en: string|null}  $text
     * @param  array<string, string>  $vars
     */
    private function render(array $text, array $vars, string $locale = 'ar'): string
    {
        return MessageTemplates::fill($locale === 'en' && $text['en'] !== null ? $text['en'] : $text['ar'], $vars);
    }
}
