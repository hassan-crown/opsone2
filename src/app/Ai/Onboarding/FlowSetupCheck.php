<?php

namespace App\Ai\Onboarding;

use App\Automation\AutomationRules;
use App\Configuration\ConfigCheck;
use App\Configuration\ConfigDiff;
use App\Configuration\ConfigReleases;
use App\Configuration\ConfigSnapshot;
use App\Finance\Plans\PaymentPlans;
use App\Models\Automation\AutomationRule;
use App\Models\Flow;
use App\Workflow\FlowCheck;
use Illuminate\Validation\ValidationException;

/**
 * The checks every proposal passes before the specialist sees it and again before it is written (AI-1): FlowCheck on
 * the flow, then the configuration draft it would make (current draft tables + the proposed plan as default + the
 * proposed rules, disabled) through the plan and rule validation and ConfigCheck — all against the PROPOSED flow,
 * since the plan's stages and gates belong to it. Publishing still runs the real FlowCheck and ConfigCheck.
 *
 * Not final: tests replace it to prove a broken check fails the request visibly.
 */
class FlowSetupCheck
{
    public function __construct(private readonly FlowCheck $flowCheck) {}

    /**
     * @param  array<string, mixed>  $payload  FlowSetupDraft payload
     * @return array{passes: bool, flow_issues: list<array<string, mixed>>, config_errors: array<string, string>, flow: array{stages: list<array<string, mixed>>}|null}
     */
    public function run(array $payload): array
    {
        $flow = $this->flowCheck->run((array) ($payload['flow'] ?? []));
        $stages = $flow->passes() ? $flow->stages : (array) ($payload['flow']['stages'] ?? []);
        $errors = PaymentPlans::assumingFlow(array_values($stages), fn () => $this->configErrors($payload));

        return [
            'passes' => $flow->passes() && $errors === [],
            'flow_issues' => $flow->issues,
            'config_errors' => $errors,
            'flow' => $flow->passes() ? $flow->definition() : null,
        ];
    }

    /**
     * The configuration draft as it would be after applying (call inside PaymentPlans::assumingFlow()).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function candidate(array $payload): array
    {
        $snapshot = ConfigSnapshot::capture();
        $plan = $payload['payment_plan'] ?? null;
        if (is_array($plan)) {
            $lines = self::prefixed("payment_plans.{$plan['key']}", fn () => PaymentPlans::normalize($plan['lines']));
            $others = array_values(array_filter($snapshot['payment_plans'], fn (array $p) => $p['key'] !== $plan['key']));
            $snapshot['payment_plans'] = [
                ...array_map(fn (array $p) => [...$p, 'is_default' => false], $others),
                ['id' => null, 'key' => $plan['key'], 'name' => $plan['name'], 'is_default' => true, 'lines' => $lines],
            ];
            usort($snapshot['payment_plans'], fn (array $a, array $b) => $a['key'] <=> $b['key']);
        }

        $existing = array_column($snapshot['automation'], 'key');
        $position = (int) AutomationRule::query()->max('position');
        foreach ((array) ($payload['automation'] ?? []) as $rule) {
            if (in_array($rule['key'], $existing, true)) {
                continue; // never replaces a rule the facility already has (see ApplyFlowSetup)
            }
            $definition = self::prefixed("automation.{$rule['key']}", fn () => AutomationRules::normalize([...$rule, 'enabled' => false]));
            $snapshot['automation'][] = [...$definition, 'position' => ++$position];
        }

        return $snapshot;
    }

    /**
     * Readable changes for the review screen: the configuration draft through ConfigDiff, and the flow stage by stage
     * against the published flow, with where each stage came from.
     *
     * @param  array<string, mixed>  $payload
     * @return array{config: list<array<string, mixed>>, flow: list<array<string, mixed>>}
     */
    public function diff(array $payload): array
    {
        /** @var list<array<string, mixed>> $stages */
        $stages = array_values((array) ($payload['flow']['stages'] ?? []));
        try {
            $config = PaymentPlans::assumingFlow($stages, fn () => ConfigDiff::between(ConfigSnapshot::capture(), self::candidate($payload)));
        } catch (ValidationException) {
            $config = [];
        }

        return ['config' => $config, 'flow' => self::flowDiff(Flow::current()?->publishedVersion?->definition['stages'] ?? [], $stages, (array) ($payload['origins'] ?? []))];
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @param  array<string, string>  $origins
     * @return list<array{key: string, name: string, change: string, origin: string|null, target_days: mixed, before_days: mixed, actions: list<array{key: string, name: string, type: string, change: string}>}>
     */
    public static function flowDiff(array $before, array $after, array $origins): array
    {
        $old = [];
        foreach ($before as $stage) {
            $old[(string) $stage['key']] = $stage;
        }
        $out = [];
        foreach ($after as $stage) {
            $key = (string) ($stage['key'] ?? '');
            $prev = $old[$key] ?? null;
            unset($old[$key]);
            $prevActions = [];
            foreach ((array) ($prev['actions'] ?? []) as $a) {
                $prevActions[(string) $a['key']] = $a;
            }
            $actions = [];
            foreach ((array) ($stage['actions'] ?? []) as $a) {
                $p = $prevActions[(string) ($a['key'] ?? '')] ?? null;
                unset($prevActions[(string) ($a['key'] ?? '')]);
                $actions[] = ['key' => (string) ($a['key'] ?? ''), 'name' => (string) ($a['name']['ar'] ?? ''), 'type' => (string) ($a['type'] ?? ''),
                    'change' => $p === null ? 'added' : (self::sameAction($p, $a) ? 'same' : 'changed')];
            }
            foreach ($prevActions as $a) {
                $actions[] = ['key' => (string) $a['key'], 'name' => (string) ($a['name']['ar'] ?? ''), 'type' => (string) $a['type'], 'change' => 'removed'];
            }
            $changed = $prev === null ? 'added' : (($prev['target_days'] ?? null) !== ($stage['target_days'] ?? null)
                || ($prev['name']['ar'] ?? null) !== ($stage['name']['ar'] ?? null)
                || array_filter($actions, fn (array $a) => $a['change'] !== 'same') !== [] ? 'changed' : 'same');
            $out[] = ['key' => $key, 'name' => (string) ($stage['name']['ar'] ?? ''), 'change' => $changed, 'origin' => $origins[$key] ?? null,
                'target_days' => $stage['target_days'] ?? null, 'before_days' => $prev['target_days'] ?? null, 'actions' => $actions];
        }
        foreach ($old as $key => $stage) {
            $out[] = ['key' => (string) $key, 'name' => (string) ($stage['name']['ar'] ?? ''), 'change' => 'removed', 'origin' => null,
                'target_days' => null, 'before_days' => $stage['target_days'] ?? null, 'actions' => []];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function configErrors(array $payload): array
    {
        try {
            $candidate = self::candidate($payload);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[(string) $field] = (string) ($messages[0] ?? '');
            }

            return $errors;
        }

        return ConfigCheck::errors($candidate, ConfigReleases::current());
    }

    /**
     * Runs a validation and re-keys its errors under the item they belong to.
     *
     * @template T
     *
     * @param  \Closure(): T  $check
     * @return T
     */
    private static function prefixed(string $prefix, \Closure $check): mixed
    {
        try {
            return $check();
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors["{$prefix}.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameAction(array $a, array $b): bool
    {
        foreach (['type', 'requirement', 'applies_when', 'inputs', 'after'] as $field) {
            if (($a[$field] ?? null) != ($b[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
