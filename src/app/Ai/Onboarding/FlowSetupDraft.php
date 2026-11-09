<?php

namespace App\Ai\Onboarding;

use App\Automation\AutomationRules;
use App\Automation\AutomationTriggers;
use App\Models\RoleTemplate;
use App\Workflow\Catalogue\ActionType;
use App\Workflow\FlowTemplates;

/**
 * Turns the model's answer (FlowSetupSchema) into the draft as the application stores it: a flow definition in the
 * shape of FlowTemplates::stage()/action() (checked by FlowCheck), one payment plan in the editor's shape
 * (PaymentPlans::rules()) and automation rules from the ready recipes, always disabled. Nothing here decides what is
 * valid: unknown or out-of-range values are passed through as they are so FlowCheck / ConfigCheck report them to the
 * specialist instead of being silently «fixed».
 *
 * Payload: {schema, base_template, summary, flow: {stages}, origins: {stage_key: template|description|both},
 *           payment_plan: {key, name, is_default, lines} | null, automation: list<definition>, questions: list}
 */
final class FlowSetupDraft
{
    /** The proposed plan's key; applying again updates the same plan. */
    public const PLAN_KEY = 'ai_setup';

    /** Prefix of proposed rule keys, so they never take the key of a rule the facility made itself. */
    public const RULE_PREFIX = 'ai_';

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function fromOutput(array $output): array
    {
        $stages = [];
        $origins = [];
        foreach ((array) ($output['stages'] ?? []) as $stage) {
            $stage = (array) $stage;
            $key = (string) ($stage['key'] ?? '');
            $origins[$key] = (string) ($stage['origin'] ?? 'description');
            $stages[] = [
                'key' => $key,
                'name' => ['ar' => trim((string) ($stage['name'] ?? '')), 'en' => null],
                'public_name' => self::text($stage['customer_name'] ?? null) === null ? null : ['ar' => self::text($stage['customer_name']), 'en' => null],
                'show_to_customer' => true,
                'follow_up' => ($stage['follow_up'] ?? null) === 'workshop' ? self::workshopFollowUp() : ['type' => 'order_assignee'],
                'target_days' => $stage['target_days'] ?? null,
                'actions' => array_map(fn ($a) => self::action((array) $a), array_values((array) ($stage['actions'] ?? []))),
            ];
        }

        $base = (string) ($output['base_template'] ?? '');

        return [
            'schema' => FlowSetupSchema::VERSION,
            'base_template' => in_array($base, FlowTemplates::keys(), true) ? $base : FlowTemplates::DEFAULT,
            'summary' => mb_substr(trim((string) ($output['summary'] ?? '')), 0, 2000),
            'flow' => ['stages' => $stages],
            'origins' => $origins,
            'payment_plan' => is_array($output['payment_plan'] ?? null) ? self::plan($output['payment_plan']) : null,
            'automation' => self::rules((array) ($output['automation'] ?? [])),
            'questions' => array_values(array_slice(array_filter(array_map(fn ($q) => is_array($q) && self::text($q['question'] ?? null) !== null
                ? ['question' => mb_substr((string) self::text($q['question']), 0, 500), 'about' => self::text($q['about'] ?? null)]
                : null, (array) ($output['questions'] ?? []))), 0, FlowSetupSchema::MAX_QUESTIONS)),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private static function action(array $a): array
    {
        $type = ActionType::tryFrom((string) ($a['type'] ?? ''));
        $name = self::text($a['name'] ?? null);

        return [
            'key' => (string) ($a['key'] ?? ''),
            'type' => (string) ($a['type'] ?? ''),
            'name' => $name === null ? ($type?->label() ?? ['ar' => '', 'en' => null]) : ['ar' => $name, 'en' => null],
            'requirement' => ($a['required'] ?? true) === false ? 'optional' : 'required',
            'applies_when' => (string) ($a['applies_when'] ?? 'always'),
            'assign' => ['type' => 'stage'],
            'inputs' => $type === null ? [] : self::inputs($type, $a),
            'after' => self::text($a['after'] ?? null),
        ];
    }

    /**
     * The action's inputs from the flat answer fields; missing ones take the catalogue default.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private static function inputs(ActionType $type, array $a): array
    {
        $default = $type->defaultInputs();
        $pick = fn (string $field, string $input) => $a[$field] ?? $default[$input] ?? null;

        return match ($type) {
            ActionType::CaptureNeeds, ActionType::RecordDelivery => [],
            ActionType::RecordMeasurements => ['purpose' => $pick('measurement_purpose', 'purpose'), 'measurement_kind' => $pick('measurement_kind', 'measurement_kind')],
            ActionType::PrepareDesign => ['requires_attachment' => $pick('requires_attachment', 'requires_attachment')],
            ActionType::PrepareDocument => ['document' => $pick('document', 'document')],
            ActionType::CustomerApproval => ['subject' => $pick('approval_subject', 'subject')],
            ActionType::WorkTask => [
                'label' => ['ar' => self::text($a['task_label'] ?? null) ?? self::text($a['name'] ?? null) ?? $default['label']['ar'], 'en' => null],
                'requires_attachment' => $pick('requires_attachment', 'requires_attachment'),
            ],
            ActionType::SupplyFollowUp => ['reference' => $pick('supply_reference', 'reference')],
            ActionType::WorkshopOrder => ['reference' => $pick('workshop_reference', 'reference'), 'milestone' => $pick('workshop_milestone', 'milestone')],
            // The share always comes from the payment plan (ADR-0037): the flow only waits for the due.
            ActionType::PaymentCheck => ['reference' => $pick('payment_reference', 'reference'), 'basis' => 'due', 'value' => null],
            ActionType::RequiredDocument => ['document_type' => $pick('document_type', 'document_type'), 'review_required' => false],
        };
    }

    /**
     * The plan in the editor's shape (values as text: «40»), the default plan once applied.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function plan(array $plan): array
    {
        $lines = [];
        foreach ((array) ($plan['lines'] ?? []) as $line) {
            $line = (array) $line;
            $percent = $line['percent'] ?? null;
            $lines[] = [
                'key' => (string) ($line['key'] ?? ''),
                'name' => ['ar' => trim((string) ($line['name'] ?? '')), 'en' => null],
                'basis' => $percent === null ? 'rest' : 'percent',
                'value' => $percent === null ? null : (string) $percent,
                'event' => (string) ($line['event'] ?? ''),
                'stage_key' => self::text($line['stage_key'] ?? null),
                'offset_days' => (int) ($line['offset_days'] ?? 0),
                'grace_days' => (int) ($line['grace_days'] ?? 0),
            ];
        }

        return [
            'key' => self::PLAN_KEY,
            'name' => ['ar' => self::text($plan['name'] ?? null) ?? 'خطة الدفع المقترحة', 'en' => null],
            'is_default' => true,
            'lines' => $lines,
        ];
    }

    /**
     * Rules from the ready recipes only (ADR-0042), with the model's parameter when the trigger takes one, and
     * `enabled = false` whatever was asked: switching a rule on is the specialist's decision after the simulation.
     *
     * @param  array<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private static function rules(array $items): array
    {
        $out = [];
        foreach (array_slice($items, 0, FlowSetupSchema::MAX_RULES) as $item) {
            $recipe = is_array($item) ? (string) ($item['recipe'] ?? '') : '';
            $template = AutomationRules::TEMPLATES[$recipe] ?? null;
            if ($template === null || isset($out[$recipe])) {
                continue;
            }
            $params = $template['params'];
            $param = AutomationTriggers::get($template['trigger'])['param'] ?? null;
            if ($param !== null && is_int($item['param'] ?? null)) {
                $params[$param['name']] = $item['param'];
            }
            $out[$recipe] = [
                'key' => self::RULE_PREFIX.$recipe,
                'name' => ['ar' => $template['name']['ar'], 'en' => $template['name']['en']],
                'trigger' => $template['trigger'],
                'params' => $params,
                'conditions' => $template['conditions'],
                'actions' => $template['actions'],
                'enabled' => false,
                'why' => mb_substr(trim((string) ($item['why'] ?? '')), 0, 500),
            ];
        }

        return array_values($out);
    }

    /**
     * Who follows the workshop stages: the workshop role when the facility has it, else the order's owner (as the
     * retail templates do).
     *
     * @return array<string, mixed>
     */
    private static function workshopFollowUp(): array
    {
        $role = RoleTemplate::query()->where('key', FlowTemplates::WORKSHOP_ROLE)->value('id');

        return $role === null ? ['type' => 'order_assignee'] : ['type' => 'role', 'id' => (int) $role];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
