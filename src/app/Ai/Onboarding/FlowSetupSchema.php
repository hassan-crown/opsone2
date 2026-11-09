<?php

namespace App\Ai\Onboarding;

use App\Automation\AutomationRules;
use App\Enums\DueEvent;
use App\Workflow\Catalogue\ActionType;
use App\Workflow\FlowCheck;
use App\Workflow\FlowTemplates;

/**
 * The strict output schema of the setup proposal (AI-1, plan §2 axis 1). Every choice the model makes is a closed
 * list taken from the code: the base template, the action catalogue (ADR-0012), the payment-plan triggers (ADR-0037)
 * and the ready automation recipes (ADR-0042). It holds only what the API's structured outputs enforce (types, enums,
 * required, no extra properties): lengths, ranges and every cross-reference are checked after the answer by
 * FlowSetupDraft (mapping), FlowCheck and ConfigCheck, never trusted from the model.
 *
 * The model never names people, roles, departments or branches (they are ids it cannot know): stages follow the
 * order's responsible person or the workshop role, actions go to the stage's responsible person.
 */
final class FlowSetupSchema
{
    /** Bumped when the shape changes; stored with the draft and in the evaluation set. */
    public const VERSION = 1;

    public const MAX_QUESTIONS = 10;

    public const MAX_RULES = 5;

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $nullableEnum = fn (array $values) => ['type' => ['string', 'null'], 'enum' => [...$values, null]];

        $action = self::object([
            'key' => $string,
            'type' => ['type' => 'string', 'enum' => array_map(fn (ActionType $t) => $t->value, ActionType::cases())],
            'name' => $string,
            'required' => ['type' => 'boolean'],
            'applies_when' => ['type' => 'string', 'enum' => FlowCheck::CONDITIONS],
            'after' => $nullableString,
            // Inputs by action type; null when the type does not use them.
            'document' => $nullableEnum(ActionType::DOCUMENTS),
            'approval_subject' => $nullableEnum(ActionType::APPROVAL_SUBJECTS),
            'payment_reference' => $nullableString,
            'workshop_reference' => $nullableString,
            'workshop_milestone' => $nullableEnum(ActionType::WORKSHOP_MILESTONES),
            'supply_reference' => $nullableString,
            'document_type' => $nullableString,
            'measurement_purpose' => $nullableEnum(['initial', 'final']),
            'measurement_kind' => $nullableEnum(ActionType::MEASUREMENT_KINDS),
            'requires_attachment' => ['type' => ['boolean', 'null']],
            'task_label' => $nullableString,
        ]);

        $stage = self::object([
            'key' => $string,
            'name' => $string,
            'customer_name' => $nullableString,
            'target_days' => ['type' => 'integer'],
            'follow_up' => ['type' => 'string', 'enum' => ['order_assignee', 'workshop']],
            'origin' => ['type' => 'string', 'enum' => ['template', 'description', 'both']],
            'actions' => ['type' => 'array', 'items' => $action],
        ]);

        $line = self::object([
            'key' => $string,
            'name' => $string,
            // Whole percent of the agreed value; null = the rest (exactly one, last).
            'percent' => ['type' => ['integer', 'null']],
            'event' => ['type' => 'string', 'enum' => DueEvent::planTriggers()],
            'stage_key' => $nullableString,
            'offset_days' => ['type' => 'integer'],
            'grace_days' => ['type' => 'integer'],
        ]);

        return self::object([
            'base_template' => ['type' => 'string', 'enum' => FlowTemplates::keys()],
            'summary' => $string,
            'stages' => ['type' => 'array', 'items' => $stage],
            'payment_plan' => [
                'anyOf' => [
                    self::object(['name' => $string, 'lines' => ['type' => 'array', 'items' => $line]]),
                    ['type' => 'null'],
                ],
            ],
            'automation' => ['type' => 'array', 'items' => self::object([
                'recipe' => ['type' => 'string', 'enum' => array_keys(AutomationRules::TEMPLATES)],
                'param' => ['type' => ['integer', 'null']],
                'why' => $string,
            ])],
            'questions' => ['type' => 'array', 'items' => self::object([
                'question' => $string,
                'about' => $nullableString,
            ])],
        ]);
    }

    /**
     * An object whose properties are all required (null where optional) and closed.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
