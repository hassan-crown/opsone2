<?php

namespace App\Automation;

use App\Configuration\ConfigReleases;
use App\Finance\Plans\PaymentPlans;
use App\Models\ConfigRelease;
use App\Support\RequestCache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Automation rules as definitions (ADR-0042): validation of a rule, the rules a release carries, the ready templates,
 * and which triggers any release listens to (so recording an event is a lookup, not a scan of rules).
 *
 * A definition: `{key, name{ar,en}, trigger, params{days|hours}, conditions{match, items}, actions[1..5], enabled,
 * position}`. Rules are part of the configuration release (section `automation`): drafted, tried, published; an
 * order follows the release it pinned (ADR-0040), other subjects the current release.
 */
final class AutomationRules
{
    public const MAX_RULES = 100;

    /**
     * Ready templates the builder starts from (analysis §4.6 «وصفات»): a draft is made from one and edited freely.
     *
     * @var array<string, array{name: array{ar: string, en: string}, trigger: string, params: array<string, int>, conditions: array{match: string, items: list<array<string, mixed>>}, actions: list<array<string, mixed>>}>
     */
    public const TEMPLATES = [
        'payment_reminder_3d' => [
            'name' => ['ar' => 'تذكير بالدفعة قبل استحقاقها بـ 3 أيام', 'en' => 'Payment reminder 3 days before due'],
            'trigger' => 'payment.due_soon', 'params' => ['days' => 3],
            'conditions' => ['match' => 'all', 'items' => [['field' => 'due.amount', 'op' => 'gt', 'value' => 0]]],
            'actions' => [['type' => 'send_message', 'template' => 'payment_due', 'channel' => 'auto']],
        ],
        'lead_untouched_48h' => [
            'name' => ['ar' => 'تنبيه المدير عند عدم متابعة عميل محتمل 48 ساعة', 'en' => 'Alert the manager when a lead is untouched for 48h'],
            'trigger' => 'lead.stale', 'params' => ['hours' => 48],
            'conditions' => ['match' => 'all', 'items' => [['field' => 'lead.open', 'op' => 'is_true', 'value' => null]]],
            'actions' => [['type' => 'notify', 'to' => ['type' => 'role', 'role' => 'manager'], 'message' => ['ar' => 'العميل المحتمل {subject} بلا متابعة منذ 48 ساعة.', 'en' => 'Lead {subject} has had no follow-up for 48 hours.']]],
        ],
        'installation_scheduled_notify' => [
            'name' => ['ar' => 'إبلاغ العميل عند جدولة التركيب', 'en' => 'Tell the customer when installation is scheduled'],
            'trigger' => 'appointment.confirmed', 'params' => [],
            'conditions' => ['match' => 'all', 'items' => [['field' => 'appointment.kind', 'op' => 'eq', 'value' => 'installation']]],
            'actions' => [['type' => 'send_message', 'template' => 'installation_scheduled', 'channel' => 'auto']],
        ],
        'overdue_follow_up' => [
            'name' => ['ar' => 'مهمة متابعة تحصيل بعد تأخر الدفعة 7 أيام', 'en' => 'Collection follow-up task 7 days after a missed due'],
            'trigger' => 'payment.overdue', 'params' => ['days' => 7],
            'conditions' => ['match' => 'all', 'items' => [['field' => 'due.amount', 'op' => 'gt', 'value' => 0]]],
            'actions' => [
                ['type' => 'create_task', 'title' => ['ar' => 'متابعة تحصيل الطلب {order}', 'en' => 'Follow up collection of {order}'], 'sla_days' => 2, 'to' => ['type' => 'responsible']],
                ['type' => 'add_note', 'body' => ['ar' => 'تأخرت دفعة العميل {customer} أكثر من 7 أيام؛ أُنشئت مهمة متابعة.', 'en' => 'A due of {customer} is over 7 days late; a follow-up task was created.']],
            ],
        ],
        'remark_opened_alert' => [
            'name' => ['ar' => 'تنبيه المدير بملاحظة تركيب مفتوحة', 'en' => 'Alert the manager about an open installation remark'],
            'trigger' => 'installation.remark_opened', 'params' => [],
            'conditions' => ['match' => 'all', 'items' => []],
            'actions' => [
                ['type' => 'notify', 'to' => ['type' => 'role', 'role' => 'manager'], 'message' => ['ar' => 'فُتحت ملاحظة تركيب على الطلب {order} للعميل {customer}.', 'en' => 'An installation remark was opened on {order} ({customer}).']],
                ['type' => 'create_task', 'title' => ['ar' => 'معالجة ملاحظة التركيب على {order}', 'en' => 'Resolve the installation remark on {order}'], 'sla_days' => 1, 'to' => ['type' => 'responsible']],
            ],
        ],
    ];

    /**
     * Validates a rule as the builder sends it; returns the stored definition (without `position`).
     *
     * @param  array<string, mixed>  $data
     * @return array{key: string, name: array{ar: string, en: string|null}, trigger: string, params: array<string, int>, conditions: array{match: string, items: list<array{field: string, op: string, value: mixed}>}, actions: list<array<string, mixed>>, enabled: bool}
     */
    public static function normalize(array $data): array
    {
        $data = Validator::make($data, [
            'key' => ['required', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:120'],
            'name.en' => ['nullable', 'string', 'max:120'],
            'trigger' => ['required', 'string', 'in:'.implode(',', AutomationTriggers::keys())],
            'params' => ['nullable', 'array'],
            'conditions' => ['nullable', 'array'],
            'actions' => ['required', 'array', 'min:1', 'max:'.AutomationActions::MAX_ACTIONS],
            'actions.*' => ['array'],
            'enabled' => ['required', 'boolean'],
        ])->validate();
        $trigger = (string) $data['trigger'];
        $param = AutomationTriggers::get($trigger)['param'] ?? null;
        $params = [];
        if ($param !== null) {
            $value = $data['params'][$param['name']] ?? null;
            if (! is_numeric($value) || (int) $value < $param['min'] || (int) $value > $param['max']) {
                throw ValidationException::withMessages(['params.'.$param['name'] => __('automation.validation.param', ['min' => $param['min'], 'max' => $param['max']])]);
            }
            $params[$param['name']] = (int) $value;
        }

        return [
            'key' => (string) $data['key'],
            'name' => ['ar' => trim((string) $data['name']['ar']), 'en' => isset($data['name']['en']) && trim((string) $data['name']['en']) !== '' ? trim((string) $data['name']['en']) : null],
            'trigger' => $trigger,
            'params' => $params,
            'conditions' => AutomationConditions::normalize($trigger, $data['conditions'] ?? null, PaymentPlans::stageKeys()),
            'actions' => array_map(fn (array $a, int $i) => AutomationActions::normalize($a, "actions.{$i}"), array_values($data['actions']), array_keys(array_values($data['actions']))),
            'enabled' => (bool) $data['enabled'],
        ];
    }

    /**
     * Release-time check of the automation section (members still active, roles and stages still present).
     *
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, string>
     */
    public static function errors(array $rules): array
    {
        $errors = [];
        if (count($rules) > self::MAX_RULES) {
            $errors['automation'] = __('automation.validation.too_many_rules', ['max' => self::MAX_RULES]);
        }
        foreach ($rules as $rule) {
            try {
                self::normalize([...$rule, 'params' => $rule['params'] ?? []]);
            } catch (ValidationException $e) {
                $errors['automation.'.($rule['key'] ?? '?')] = (string) collect($e->errors())->flatten()->first();
            }
        }

        return $errors;
    }

    /**
     * The rules of a snapshot for one trigger, in order.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public static function forTrigger(array $snapshot, string $trigger): array
    {
        $rules = array_filter((array) ($snapshot['automation'] ?? []), fn (array $r) => $r['trigger'] === $trigger);
        usort($rules, fn (array $a, array $b) => [$a['position'] ?? 0, $a['key']] <=> [$b['position'] ?? 0, $b['key']]);

        return $rules;
    }

    /**
     * The triggers of a snapshot's rules (stored on the release when it is published).
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    public static function triggersOf(array $snapshot): array
    {
        $triggers = array_values(array_unique(array_column($snapshot['automation'] ?? [], 'trigger')));
        sort($triggers);

        return $triggers;
    }

    /**
     * The triggers any release listens to: an order pinned to an older release may still need an event the current
     * release no longer uses.
     *
     * @return list<string>
     */
    public static function liveTriggers(): array
    {
        return app(RequestCache::class)->remember('automation_live_triggers', function () {
            if (ConfigReleases::latest() === null) {
                return [];
            }
            $all = [];
            foreach (ConfigRelease::query()->whereNotNull('automation_triggers')->pluck('automation_triggers') as $list) {
                $decoded = is_string($list) ? json_decode($list, true) : $list;
                $all = [...$all, ...(is_array($decoded) ? $decoded : [])];
            }

            return array_values(array_unique($all));
        });
    }

    /**
     * Timer parameters the releases use per timer trigger (the scan evaluates each once).
     *
     * @return array<string, list<int>>
     */
    public static function timerParams(): array
    {
        $out = [];
        $seen = [];
        foreach (ConfigRelease::query()->whereNotNull('automation_triggers')->orderByDesc('number')->get() as $release) {
            if (array_intersect(AutomationTriggers::timers(), (array) $release->automation_triggers) === [] || isset($seen[$release->snapshot_hash])) {
                continue;
            }
            $seen[$release->snapshot_hash] = true;
            foreach ($release->snapshot['automation'] ?? [] as $rule) {
                if (in_array($rule['trigger'], AutomationTriggers::timers(), true) && ($rule['enabled'] ?? false)) {
                    $out[$rule['trigger']][] = (int) AutomationTriggers::param($rule['trigger'], $rule['params'] ?? []);
                }
            }
        }

        return array_map(fn (array $v) => array_values(array_unique($v)), $out);
    }
}
