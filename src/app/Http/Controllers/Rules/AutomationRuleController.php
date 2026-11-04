<?php

namespace App\Http\Controllers\Rules;

use App\Actions\Rules\RulesAccess;
use App\Actions\Rules\SaveAutomationRule;
use App\Automation\AutomationActions;
use App\Automation\AutomationConditions;
use App\Automation\AutomationRules;
use App\Automation\AutomationSimulator;
use App\Automation\AutomationText;
use App\Automation\AutomationTriggers;
use App\Configuration\ConfigReleases;
use App\Enums\MembershipStatus;
use App\Finance\Plans\PaymentPlans;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\AutomationRuleRequest;
use App\Models\Automation\AutomationRule;
use App\Models\Automation\AutomationRun;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\RoleTemplate;
use App\Whatsapp\WhatsAppEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › automation (ADR-0042): the rules list (draft, whether each is in the release in force, the kill switch,
 * last 7 days of runs), the builder «عند … وإذا … فافعل …» with ready templates and «try it» on recent real events,
 * and each rule's execution log. Reading needs P-104 or P-02; every write needs P-104 (checked in the action).
 */
class AutomationRuleController extends Controller
{
    private const LOG_ROWS = 100;

    public function index(Request $request): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        /** @var list<array<string, mixed>> $section */
        $section = ConfigReleases::current()['automation'] ?? [];
        $released = array_column($section, null, 'key');
        $stats = AutomationRun::query()->where('created_at', '>=', now()->subDays(7))
            ->select('rule_key', 'outcome', DB::raw('count(*) as total'))->groupBy('rule_key', 'outcome')->get()
            ->groupBy('rule_key')->map(fn ($rows) => $rows->pluck('total', 'outcome')->map(fn ($n) => (int) $n));

        return Inertia::render('rules/automation', [
            'rules' => array_values(AutomationRule::query()->orderByRaw('archived_at is not null')->orderBy('position')->orderBy('key')->get()
                ->map(fn (AutomationRule $r) => [
                    'id' => $r->id, 'key' => $r->key, 'name' => $r->name->toArray(), 'trigger' => $r->trigger, 'params' => $r->trigger_params ?? [],
                    'conditions' => AutomationText::conditions($r->conditions), 'actions' => array_column($r->actions, 'type'),
                    'enabled' => $r->enabled, 'archived' => $r->archived_at !== null,
                    'killed' => $r->killed_at === null ? null : ['at' => $r->killed_at->toIso8601String(), 'reason' => $r->kill_reason],
                    'released' => isset($released[$r->key]) ? ($released[$r->key] == $r->definition() ? 'same' : 'changed') : 'draft',
                    'stats' => ['matched' => $stats[$r->key][AutomationRun::MATCHED] ?? 0, 'skipped' => $stats[$r->key][AutomationRun::SKIPPED] ?? 0, 'failed' => $stats[$r->key][AutomationRun::FAILED] ?? 0],
                    'lock_version' => $r->lock_version,
                ])->all()),
            'templates' => array_map(fn (string $key, array $t) => ['key' => $key, 'name' => $t['name'], 'trigger' => $t['trigger']], array_keys(AutomationRules::TEMPLATES), AutomationRules::TEMPLATES),
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function create(Request $request): Response
    {
        RulesAccess::authorizeView($request->user());
        $template = AutomationRules::TEMPLATES[(string) $request->query('template')] ?? null;

        return $this->builder($request, null, $template === null ? null : [
            'key' => (string) $request->query('template'), 'name' => $template['name'], 'trigger' => $template['trigger'], 'params' => $template['params'],
            'conditions' => $template['conditions'], 'actions' => $template['actions'], 'enabled' => true,
        ]);
    }

    public function edit(Request $request, AutomationRule $rule): Response
    {
        RulesAccess::authorizeView($request->user());

        return $this->builder($request, $rule, [...$rule->definition(), 'lock_version' => $rule->lock_version]);
    }

    public function store(AutomationRuleRequest $request, SaveAutomationRule $save): RedirectResponse
    {
        $rule = $save->handle($request->user(), null, $request->safe()->except('lock_version'), null);

        return $this->saved($rule);
    }

    public function update(AutomationRuleRequest $request, AutomationRule $rule, SaveAutomationRule $save): RedirectResponse
    {
        $rule = $save->handle($request->user(), $rule, $request->safe()->except('lock_version'), $request->integer('lock_version') ?: null);

        return $this->saved($rule);
    }

    public function archive(Request $request, AutomationRule $rule, SaveAutomationRule $save): RedirectResponse
    {
        $save->archive($request->user(), $rule);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.automation');
    }

    public function kill(Request $request, AutomationRule $rule, SaveAutomationRule $save): RedirectResponse
    {
        $save->kill($request->user(), $rule, (string) $request->input('reason', ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.killed')]);

        return back();
    }

    public function revive(Request $request, AutomationRule $rule, SaveAutomationRule $save): RedirectResponse
    {
        $save->revive($request->user(), $rule);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.revived')]);

        return back();
    }

    /**
     * «Try it»: the draft rule (as edited, not yet saved) against recent real events. Sends and writes nothing.
     */
    public function try(AutomationRuleRequest $request, AutomationSimulator $simulator): JsonResponse
    {
        RulesAccess::authorizeView($request->user());
        $rule = AutomationRules::normalize($request->safe()->except(['lock_version', 'position']));

        return response()->json(['samples' => $simulator->run($rule)]);
    }

    public function log(Request $request, string $key): Response
    {
        RulesAccess::authorizeView($request->user());
        $rule = AutomationRule::query()->where('key', $key)->firstOrFail();
        $runs = AutomationRun::query()->with(['event:id,trigger,occurred_at,source', 'actions:id,automation_run_id,type,status,result'])
            ->where('rule_key', $key)->orderByDesc('id')->limit(self::LOG_ROWS)->get();

        return Inertia::render('rules/automation-log', [
            'rule' => ['id' => $rule->id, 'key' => $rule->key, 'name' => $rule->name->toArray(), 'killed' => $rule->killed_at !== null],
            'runs' => array_values($runs->map(fn (AutomationRun $run) => [
                'id' => $run->id, 'at' => $run->created_at->toIso8601String(), 'outcome' => $run->outcome, 'reason' => $run->reason,
                'subject' => class_basename($run->subject_type).' #'.$run->subject_id, 'release' => $run->config_release_id,
                'event' => ['trigger' => $run->event->trigger, 'source' => $run->event->source, 'occurred_at' => $run->event->occurred_at->toIso8601String()],
                'conditions' => $run->detail['conditions'] ?? [],
                'actions' => array_values($run->actions->map(fn ($a) => ['type' => $a->type, 'status' => $a->status, 'reason' => $a->result['reason'] ?? null])->all()),
            ])->all()),
            'canEdit' => RulesAccess::canEdit($request->user()),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $draft
     */
    private function builder(Request $request, ?AutomationRule $rule, ?array $draft): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('rules/automation-builder', [
            'rule' => $rule === null ? null : ['id' => $rule->id, 'key' => $rule->key, 'killed' => $rule->killed_at !== null],
            'draft' => $draft,
            'catalogue' => [
                'triggers' => array_map(fn (string $key, array $d) => [
                    'key' => $key, 'group' => $d['group'], 'source' => $d['source'], 'param' => $d['param'] ?? null,
                    'fields' => AutomationConditions::fieldsFor($key),
                ], array_keys(AutomationTriggers::DEFINITIONS), AutomationTriggers::DEFINITIONS),
                'fields' => array_map(fn (string $key, array $f) => [
                    'key' => $key, 'type' => $f['type'], 'options' => match ($f['type']) {
                        'branch' => Branch::query()->orderBy('id')->get()->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => $locale === 'en' && $b->name_en !== null ? $b->name_en : $b->name_ar])->all(),
                        'stage' => array_map(fn (string $s) => ['value' => $s, 'label' => $s], PaymentPlans::stageKeys()),
                        'enum' => array_map(fn (string $v) => ['value' => $v, 'label' => __("automation.value.{$key}.{$v}")], AutomationConditions::options($key)),
                        default => [],
                    },
                ], array_keys(AutomationConditions::FIELDS), AutomationConditions::FIELDS),
                'operators' => AutomationConditions::OPERATORS,
                'actions' => AutomationActions::TYPES,
                'templates' => WhatsAppEvent::values(),
                'variables' => AutomationActions::VARIABLES,
                'roles' => RoleTemplate::query()->whereNotNull('key')->orderBy('id')->get()
                    ->map(fn (RoleTemplate $r) => ['value' => (string) $r->key, 'label' => $locale === 'en' && $r->name_en !== null ? $r->name_en : $r->name_ar])->all(),
                'members' => Membership::query()->with('user:id,name')->where('status', MembershipStatus::Active)->orderBy('user_id')->get()
                    ->map(fn (Membership $m) => ['value' => (string) $m->user_id, 'label' => $m->user->name])->values()->all(),
            ],
            'canEdit' => RulesAccess::canEdit($request->user()),
        ]);
    }

    private function saved(AutomationRule $rule): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.automation.edit', $rule);
    }
}
