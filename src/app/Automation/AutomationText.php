<?php

namespace App\Automation;

use App\Models\Branch;

/**
 * Readable one-line texts of a rule's parts (ADR-0042) for the release change log and the rules list:
 * «order.total > 500000 و stage.key = install», «send_message · create_task».
 */
final class AutomationText
{
    /**
     * @param  array<mixed>  $value
     */
    public static function of(string $field, array $value): string
    {
        return match ($field) {
            'params' => implode(', ', array_map(fn ($k, $v) => __('automation.param.'.$k).' '.$v, array_keys($value), $value)),
            'conditions' => self::conditions($value),
            'actions' => implode(' · ', array_map(fn (array $a) => __('automation.action.'.($a['type'] ?? '')), $value)),
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public static function conditions(array $conditions): string
    {
        $items = array_map(function (array $c) {
            $type = AutomationConditions::FIELDS[$c['field']]['type'] ?? 'text';
            $one = fn ($v) => match ($type) {
                'money' => number_format((int) $v / 100, 2),
                'enum' => __("automation.value.{$c['field']}.{$v}"),
                'branch' => (string) (Branch::query()->whereKey((int) $v)->value('name_ar') ?? $v),
                default => (string) $v,
            };
            $value = $c['value'];
            $text = is_array($value) ? implode('، ', array_map($one, $value)) : ($value === null ? '' : $one($value));

            return trim(__('automation.field.'.$c['field']).' '.__('automation.op.'.$c['op']).' '.$text);
        }, $conditions['items'] ?? []);
        if ($items === []) {
            return __('automation.conditions.always');
        }

        return implode(' '.__('automation.match.'.($conditions['match'] ?? 'all').'_join').' ', $items);
    }
}
