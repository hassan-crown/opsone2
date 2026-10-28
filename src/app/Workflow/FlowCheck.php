<?php

namespace App\Workflow;

use App\Access\Contracts\LicenseCapabilities;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\Department;
use App\Models\Membership;
use App\Workflow\Catalogue\ActionType;
use Illuminate\Support\Facades\Validator;

/**
 * Pre-publish check (WF-04, `FlowCheck` screen): order, references, assignments, license and the system dependencies
 * of every action. Returns every issue with where it is and how to fix it; publishing is refused while any remain.
 *
 * Also used for a running order's upcoming work (P-28): the started stages are a fixed prefix whose actions count as
 * existing prerequisites but are not checked again.
 */
final class FlowCheck
{
    public const MAX_STAGES = 15;

    public const MAX_ACTIONS = 20;

    public const KEY_PATTERN = '/^[a-z0-9_]{1,40}$/';

    public const ASSIGN_TYPES = ['order_assignee', 'member', 'department'];

    public const CONDITIONS = ['always', 'needs_measurement', 'needs_design'];

    /** @var list<array{code: string, path: string, stage: string|null, action: string|null, message: string}> */
    private array $issues = [];

    public function __construct(private readonly LicenseCapabilities $license) {}

    /**
     * @param  array<string, mixed>  $definition  `{stages: [...]}` as in docs/p5-engine-api.md
     * @param  list<array<string, mixed>>  $fixedStages  stages already started (running order only)
     */
    public function run(array $definition, array $fixedStages = []): FlowCheckResult
    {
        $this->issues = [];

        $shape = Validator::make($definition, [
            'stages' => ['present', 'array', 'max:'.self::MAX_STAGES],
        ]);
        if ($shape->fails() || ! is_array($definition['stages'] ?? null)) {
            $this->issue('invalid_structure', 'stages');

            return new FlowCheckResult($this->issues, []);
        }

        $stages = array_values($definition['stages']);
        if ($stages === [] && $fixedStages === []) {
            $this->issue('stages_empty', 'stages');
        }

        $normalized = [];
        $stageKeys = array_map(fn (array $s) => (string) ($s['key'] ?? ''), $fixedStages);
        $actionKeys = [];
        foreach ($fixedStages as $stage) {
            foreach ($stage['actions'] ?? [] as $action) {
                $actionKeys[] = (string) $action['key'];
            }
        }

        foreach ($stages as $i => $stage) {
            $normalized[] = $this->stage(is_array($stage) ? $stage : [], "stages.{$i}", $stageKeys, $actionKeys);
        }

        $this->dependencies([...$fixedStages, ...$normalized], count($fixedStages));

        return new FlowCheckResult($this->issues, $this->issues === [] ? $normalized : []);
    }

    /**
     * System prerequisites of every action in a (valid) definition: `key => [keys]`. The editor never draws links;
     * these come from the fixed catalogue plus the optional «after» of the same or an earlier stage.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return array<string, list<string>>
     */
    public static function dependencyMap(array $stages): array
    {
        $flat = self::flatten($stages);
        $map = [];

        foreach ($flat as $a) {
            $type = ActionType::from($a['type']);
            $candidates = [];
            foreach ($flat as $b) {
                if ($b['key'] === $a['key'] || $b['stage'] > $a['stage']) {
                    continue;
                }
                if ($type->dependsOn($a['inputs'], ActionType::from($b['type']), $b['inputs'])) {
                    $candidates[] = $b;
                }
            }
            // Alternatives (e.g. the quote's or the optional contract's approval before a percentage): wait for the
            // required ones when there are any, so an optional alternative never blocks.
            $required = array_filter($candidates, fn (array $b) => $b['requirement'] === 'required');
            $deps = array_map(fn (array $b) => $b['key'], $required !== [] ? $required : $candidates);
            if (($a['after'] ?? null) !== null) {
                $deps[] = $a['after'];
            }
            $map[$a['key']] = array_values(array_unique($deps));
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $stage
     * @param  list<string>  $stageKeys
     * @param  list<string>  $actionKeys
     * @return array<string, mixed>
     */
    private function stage(array $stage, string $path, array &$stageKeys, array &$actionKeys): array
    {
        $key = $stage['key'] ?? null;
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            $this->issue('invalid_key', "{$path}.key", is_string($key) ? $key : null);
            $key = (string) (is_scalar($key) ? $key : '');
        } elseif (in_array($key, $stageKeys, true)) {
            $this->issue('duplicate_key', "{$path}.key", $key);
        }
        $stageKeys[] = $key;

        $name = $this->name($stage['name'] ?? null, "{$path}.name", $key, null);
        $publicName = isset($stage['public_name']) ? $this->name($stage['public_name'], "{$path}.public_name", $key, null) : null;

        $days = $stage['target_days'] ?? null;
        if ($days !== null && (! is_int($days) || $days < 1 || $days > 365)) {
            $this->issue('invalid_target_days', "{$path}.target_days", $key);
            $days = null;
        }

        $followUp = $this->target($stage['follow_up'] ?? null, "{$path}.follow_up", $key, null, 'follow_up');

        $actions = $stage['actions'] ?? null;
        if (! is_array($actions) || $actions === []) {
            $this->issue('stage_without_actions', "{$path}.actions", $key);
            $actions = [];
        } elseif (count($actions) > self::MAX_ACTIONS) {
            $this->issue('too_many_actions', "{$path}.actions", $key);
        }

        $normalizedActions = [];
        foreach (array_values($actions) as $j => $action) {
            $normalizedActions[] = $this->action(is_array($action) ? $action : [], "{$path}.actions.{$j}", $key, $actionKeys);
        }

        return [
            'key' => $key,
            'name' => $name,
            'public_name' => $publicName,
            'show_to_customer' => (bool) ($stage['show_to_customer'] ?? true),
            'follow_up' => $followUp,
            'target_days' => $days,
            'actions' => $normalizedActions,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<string>  $actionKeys
     * @return array<string, mixed>
     */
    private function action(array $action, string $path, string $stageKey, array &$actionKeys): array
    {
        $key = $action['key'] ?? null;
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            $this->issue('invalid_key', "{$path}.key", $stageKey);
            $key = (string) (is_scalar($key) ? $key : '');
        } elseif (in_array($key, $actionKeys, true)) {
            $this->issue('duplicate_key', "{$path}.key", $stageKey, $key);
        }
        $actionKeys[] = $key;

        $type = ActionType::tryFrom(is_string($action['type'] ?? null) ? $action['type'] : '');
        if ($type === null) {
            $this->issue('unknown_action_type', "{$path}.type", $stageKey, $key);

            return ['key' => $key, 'type' => null];
        }

        if ($type->capability() !== null && ! $this->license->allows($type->capability())) {
            $this->issue('capability_unavailable', "{$path}.type", $stageKey, $key);
        }

        $requirement = $action['requirement'] ?? 'required';
        if (! in_array($requirement, ['required', 'optional'], true)) {
            $this->issue('invalid_requirement', "{$path}.requirement", $stageKey, $key);
        }
        $when = $action['applies_when'] ?? 'always';
        if (! in_array($when, self::CONDITIONS, true)) {
            $this->issue('invalid_condition', "{$path}.applies_when", $stageKey, $key);
        }

        $inputs = is_array($action['inputs'] ?? null) ? $action['inputs'] : $type->defaultInputs();
        $validator = Validator::make($inputs, $type->inputRules());
        if ($validator->fails()) {
            foreach (array_keys($validator->errors()->messages()) as $field) {
                $this->issue('invalid_input', "{$path}.inputs.{$field}", $stageKey, $key);
            }
        }
        $inputs = array_intersect_key($inputs, $type->inputRules() + ['label' => true]);
        if ($type === ActionType::PaymentCheck) {
            $this->paymentValue($inputs, "{$path}.inputs.value", $stageKey, $key);
        }

        $after = $action['after'] ?? null;
        if ($after !== null && (! is_string($after) || $after === $key)) {
            $this->issue('invalid_after', "{$path}.after", $stageKey, $key);
            $after = null;
        }

        return [
            'key' => $key,
            'type' => $type->value,
            'name' => isset($action['name']) ? $this->name($action['name'], "{$path}.name", $stageKey, $key) : $type->label(),
            'requirement' => $requirement,
            'applies_when' => $when,
            'assign' => $this->target($action['assign'] ?? ['type' => 'order_assignee'], "{$path}.assign", $stageKey, $key, 'assign'),
            'inputs' => $inputs,
            'after' => $after,
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function paymentValue(array $inputs, string $path, string $stageKey, string $key): void
    {
        $value = $inputs['value'] ?? null;
        $ok = match ($inputs['basis'] ?? null) {
            'amount' => is_int($value) && $value > 0,
            'percent' => is_int($value) && $value >= 1 && $value <= 100,
            'remaining' => $value === null,
            default => true, // already reported as invalid_input
        };
        if (! $ok) {
            $this->issue('invalid_payment_value', $path, $stageKey, $key);
        }
    }

    /**
     * @return array{ar: string, en: string|null}
     */
    private function name(mixed $name, string $path, ?string $stageKey, ?string $actionKey): array
    {
        $ar = is_array($name) ? ($name['ar'] ?? null) : null;
        $en = is_array($name) ? ($name['en'] ?? null) : null;

        if (! is_string($ar) || trim($ar) === '' || mb_strlen($ar) > 80 || ($en !== null && (! is_string($en) || mb_strlen($en) > 80))) {
            $this->issue('name_required', $path, $stageKey, $actionKey);

            return ['ar' => is_string($ar) ? $ar : '', 'en' => null];
        }

        return ['ar' => trim($ar), 'en' => is_string($en) && trim($en) !== '' ? trim($en) : null];
    }

    /**
     * A follow-up or assignment target: the order's responsible person, an active eligible member, or an active
     * department's inbox. Templates only use the first, so they never require people a facility may not have.
     *
     * @return array{type: string, id?: int}
     */
    private function target(mixed $target, string $path, ?string $stageKey, ?string $actionKey, string $what): array
    {
        $type = is_array($target) ? ($target['type'] ?? null) : null;
        $id = is_array($target) && isset($target['id']) && is_int($target['id']) ? $target['id'] : null;

        if (! in_array($type, self::ASSIGN_TYPES, true)) {
            $this->issue("{$what}_missing", $path, $stageKey, $actionKey);

            return ['type' => 'order_assignee'];
        }
        if ($type === 'order_assignee') {
            return ['type' => $type];
        }

        $valid = $id !== null && ($type === 'member'
            ? Membership::query()->where('user_id', $id)->where('status', MembershipStatus::Active)
                ->whereHas('user', fn ($q) => $q->where('is_support', false))->exists()
            : Department::query()->whereKey($id)->where('status', OrganizationUnitStatus::Active)->exists());

        if (! $valid) {
            $this->issue("{$what}_inactive", $path, $stageKey, $actionKey);
        }

        return ['type' => $type, 'id' => (int) $id];
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     */
    private function dependencies(array $stages, int $fixedCount): void
    {
        $flat = self::flatten($stages);
        $byKey = [];
        foreach ($flat as $a) {
            $byKey[$a['key']] = $a;
        }

        $map = self::dependencyMap($stages);

        foreach ($flat as $a) {
            if ($a['stage'] < $fixedCount) {
                continue;
            }
            $type = ActionType::from($a['type']);
            $path = 'stages.'.($a['stage'] - $fixedCount).".actions.{$a['position']}";

            // «after» must point at the same or an earlier stage, never later.
            if ($a['after'] !== null && (! isset($byKey[$a['after']]) || $byKey[$a['after']]['stage'] > $a['stage'])) {
                $this->issue('invalid_after', "{$path}.after", $a['stage_key'], $a['key']);
            }

            // Mandatory prerequisites: an approval needs its document; a percentage/remaining needs the agreement.
            $code = $type->requiredPrerequisite($a['inputs']);
            if ($code !== null) {
                $candidates = array_values(array_filter(
                    $map[$a['key']],
                    fn (string $k) => isset($byKey[$k]) && $type->dependsOn($a['inputs'], ActionType::from($byKey[$k]['type']), $byKey[$k]['inputs']),
                ));
                if ($candidates === []) {
                    $this->issue($code, "{$path}.type", $a['stage_key'], $a['key']);
                } elseif (! $this->anyCovers($a, array_map(fn (string $k) => $byKey[$k], $candidates))) {
                    $this->issue('prerequisite_may_be_skipped', "{$path}.requirement", $a['stage_key'], $a['key']);
                }
            }

            // A required action must not wait for an «after» that may never happen.
            if ($a['after'] !== null && isset($byKey[$a['after']]) && ! $this->anyCovers($a, [$byKey[$a['after']]])) {
                $this->issue('prerequisite_may_be_skipped', "{$path}.after", $a['stage_key'], $a['key']);
            }

            // Repeating an action needs a different purpose; collection, receipt and delivery are never doubled.
            $reference = $type->referenceOf($a['inputs']);
            if ($reference !== null) {
                foreach ($flat as $b) {
                    if ($b['index'] < $a['index'] && $b['type'] === $a['type'] && ActionType::from($b['type'])->referenceOf($b['inputs']) === $reference) {
                        $this->issue('duplicate_reference', "{$path}.inputs", $a['stage_key'], $a['key']);
                        break;
                    }
                }
            }
        }

        $this->cycles($flat, $map, $fixedCount);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  list<array<string, mixed>>  $prerequisites
     */
    private function anyCovers(array $a, array $prerequisites): bool
    {
        foreach ($prerequisites as $b) {
            $conditionOk = $b['applies_when'] === 'always' || $b['applies_when'] === $a['applies_when'];
            $requirementOk = $a['requirement'] === 'optional' || $b['requirement'] === 'required';
            if ($conditionOk && $requirementOk) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $flat
     * @param  array<string, list<string>>  $map
     */
    private function cycles(array $flat, array $map, int $fixedCount): void
    {
        $state = [];
        $visit = function (string $key) use (&$visit, &$state, $map): bool {
            if (($state[$key] ?? 0) === 1) {
                return true;
            }
            if (($state[$key] ?? 0) === 2) {
                return false;
            }
            $state[$key] = 1;
            foreach ($map[$key] ?? [] as $dep) {
                if ($visit($dep)) {
                    return true;
                }
            }
            $state[$key] = 2;

            return false;
        };

        foreach ($flat as $a) {
            if ($a['stage'] >= $fixedCount && ($state[$a['key']] ?? 0) === 0 && $visit($a['key'])) {
                $this->issue('dependency_cycle', 'stages.'.($a['stage'] - $fixedCount).".actions.{$a['position']}.after", $a['stage_key'], $a['key']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<array{index: int, stage: int, stage_key: string, position: int, key: string, type: string, inputs: array<string, mixed>, after: string|null, requirement: string, applies_when: string}>
     */
    private static function flatten(array $stages): array
    {
        $flat = [];
        foreach ($stages as $s => $stage) {
            foreach (array_values($stage['actions'] ?? []) as $p => $action) {
                if (! is_string($action['type'] ?? null) || ActionType::tryFrom($action['type']) === null) {
                    continue;
                }
                $flat[] = [
                    'index' => count($flat),
                    'stage' => $s,
                    'stage_key' => (string) $stage['key'],
                    'position' => $p,
                    'key' => (string) $action['key'],
                    'type' => $action['type'],
                    'inputs' => is_array($action['inputs'] ?? null) ? $action['inputs'] : [],
                    'after' => $action['after'] ?? null,
                    'requirement' => $action['requirement'] ?? 'required',
                    'applies_when' => $action['applies_when'] ?? 'always',
                ];
            }
        }

        return $flat;
    }

    private function issue(string $code, string $path, ?string $stage = null, ?string $action = null): void
    {
        $this->issues[] = [
            'code' => $code,
            'path' => $path,
            'stage' => $stage,
            'action' => $action,
            'message' => __('workflow.check.'.$code),
        ];
    }
}
