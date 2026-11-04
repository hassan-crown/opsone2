<?php

namespace App\Automation;

use App\Enums\CustomerKind;
use App\Enums\DocumentKind;
use App\Enums\InterestChannel;
use App\Enums\InterestStatus;
use App\Enums\OrderKind;
use App\Models\Appointment;
use App\Models\Branch;
use Illuminate\Validation\ValidationException;

/**
 * Typed, safe conditions (ADR-0042): `{match: all|any, items: [{field, op, value}]}` over a closed list of fields of
 * the event's subject (amount, branch, stage, channel, customer flags, elapsed time). Each field has a type, the type
 * fixes the operators, and values are checked against it on save. Nothing is parsed or executed: evaluation is a
 * comparison of a live fact with a stored scalar or list.
 */
final class AutomationConditions
{
    public const MAX_ITEMS = 10;

    public const MAX_LIST = 50;

    /**
     * Field → [fact group, type]. Money is in halalas; `stage` values are flow stage keys.
     *
     * @var array<string, array{group: string, type: string}>
     */
    public const FIELDS = [
        'branch' => ['group' => 'common', 'type' => 'branch'],
        'order.total' => ['group' => 'order', 'type' => 'money'],
        'order.kind' => ['group' => 'order', 'type' => 'enum'],
        'order.stage' => ['group' => 'order', 'type' => 'stage'],
        'order.age_days' => ['group' => 'order', 'type' => 'number'],
        'order.assigned' => ['group' => 'order', 'type' => 'bool'],
        'customer.kind' => ['group' => 'customer', 'type' => 'enum'],
        'customer.has_email' => ['group' => 'customer', 'type' => 'bool'],
        'customer.whatsapp_opt_out' => ['group' => 'customer', 'type' => 'bool'],
        'stage.key' => ['group' => 'stage', 'type' => 'stage'],
        'document.kind' => ['group' => 'document', 'type' => 'enum'],
        'appointment.kind' => ['group' => 'appointment', 'type' => 'enum'],
        'lead.channel' => ['group' => 'lead', 'type' => 'enum'],
        'lead.status' => ['group' => 'lead', 'type' => 'enum'],
        'lead.age_hours' => ['group' => 'lead', 'type' => 'number'],
        'lead.assigned' => ['group' => 'lead', 'type' => 'bool'],
        'lead.open' => ['group' => 'lead', 'type' => 'bool'],
        'request.kind' => ['group' => 'request', 'type' => 'enum'],
        'request.source' => ['group' => 'request', 'type' => 'enum'],
        'task.hours_late' => ['group' => 'task', 'type' => 'number'],
        'task.assigned' => ['group' => 'task', 'type' => 'bool'],
        'due.amount' => ['group' => 'due', 'type' => 'money'],
        'due.days_late' => ['group' => 'due', 'type' => 'number'],
        'due.reference' => ['group' => 'due', 'type' => 'text'],
    ];

    /** @var array<string, list<string>> */
    public const OPERATORS = [
        'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
        'money' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
        'enum' => ['eq', 'neq', 'in', 'not_in'],
        'branch' => ['eq', 'neq', 'in', 'not_in'],
        'stage' => ['eq', 'neq', 'in', 'not_in'],
        'text' => ['eq', 'neq'],
        'bool' => ['is_true', 'is_false'],
    ];

    /**
     * The closed options of an enum field.
     *
     * @return list<string>
     */
    public static function options(string $field): array
    {
        return match ($field) {
            'order.kind' => array_map(fn (OrderKind $k) => $k->value, OrderKind::cases()),
            'customer.kind' => CustomerKind::values(),
            'document.kind' => array_map(fn (DocumentKind $k) => $k->value, DocumentKind::cases()),
            'appointment.kind', 'request.kind' => Appointment::KINDS,
            'lead.channel' => InterestChannel::values(),
            'lead.status' => InterestStatus::values(),
            'request.source' => ['site', 'portal'],
            default => [],
        };
    }

    /**
     * The fields a trigger's conditions may use.
     *
     * @return list<string>
     */
    public static function fieldsFor(string $trigger): array
    {
        $groups = ['common', ...(AutomationTriggers::get($trigger)['facts'] ?? [])];

        return array_keys(array_filter(self::FIELDS, fn (array $f) => in_array($f['group'], $groups, true)));
    }

    /**
     * Validates and normalizes conditions for a trigger; `path` prefixes error keys.
     *
     * @param  array<string, mixed>|null  $conditions
     * @param  list<string>  $stageKeys  stage keys of the published flow
     * @return array{match: string, items: list<array{field: string, op: string, value: mixed}>}
     */
    public static function normalize(string $trigger, ?array $conditions, array $stageKeys, string $path = 'conditions'): array
    {
        $conditions ??= ['match' => 'all', 'items' => []];
        $match = $conditions['match'] ?? 'all';
        if (! in_array($match, ['all', 'any'], true)) {
            throw ValidationException::withMessages(["{$path}.match" => __('automation.validation.match')]);
        }
        $items = array_values((array) ($conditions['items'] ?? []));
        if (count($items) > self::MAX_ITEMS) {
            throw ValidationException::withMessages([$path => __('automation.validation.too_many_conditions', ['max' => self::MAX_ITEMS])]);
        }
        $allowed = self::fieldsFor($trigger);
        $out = [];
        foreach ($items as $i => $item) {
            $at = "{$path}.items.{$i}";
            $field = is_array($item) ? ($item['field'] ?? null) : null;
            if (! is_string($field) || ! in_array($field, $allowed, true)) {
                throw ValidationException::withMessages(["{$at}.field" => __('automation.validation.field')]);
            }
            $type = self::FIELDS[$field]['type'];
            $op = $item['op'] ?? null;
            if (! is_string($op) || ! in_array($op, self::OPERATORS[$type], true)) {
                throw ValidationException::withMessages(["{$at}.op" => __('automation.validation.operator')]);
            }
            $out[] = ['field' => $field, 'op' => $op, 'value' => self::value($field, $type, $op, $item['value'] ?? null, $stageKeys, "{$at}.value")];
        }

        return ['match' => $match, 'items' => $out];
    }

    /**
     * @param  list<string>  $stageKeys
     */
    private static function value(string $field, string $type, string $op, mixed $value, array $stageKeys, string $at): mixed
    {
        $fail = fn () => throw ValidationException::withMessages([$at => __('automation.validation.value')]);

        if ($type === 'bool') {
            return null;
        }
        if (in_array($type, ['number', 'money'], true)) {
            $number = fn (mixed $v): int => is_int($v) || (is_string($v) && preg_match('/^-?\d{1,12}$/', $v) === 1) ? (int) $v : $fail();
            if ($op === 'between') {
                $pair = is_array($value) && count($value) === 2 ? array_map($number, array_values($value)) : $fail();

                return $pair[0] <= $pair[1] ? $pair : $fail();
            }

            return $number($value);
        }
        if ($type === 'text') {
            return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 60 ? trim($value) : $fail();
        }
        $choices = match ($type) {
            'branch' => array_map(fn ($id) => (string) $id, Branch::query()->pluck('id')->all()),
            'stage' => $stageKeys,
            default => self::options($field),
        };
        $one = fn (mixed $v): string => (is_string($v) || is_int($v)) && in_array((string) $v, $choices, true) ? (string) $v : $fail();
        if (in_array($op, ['in', 'not_in'], true)) {
            $list = is_array($value) && $value !== [] && count($value) <= self::MAX_LIST ? array_values(array_unique(array_map($one, $value))) : $fail();

            return $list;
        }

        return $one($value);
    }

    /**
     * Evaluates conditions against facts. Returns whether they hold and each item's result (for the log and dry run).
     *
     * @param  array{match?: string, items?: list<array{field: string, op: string, value: mixed}>}  $conditions
     * @param  array<string, mixed>  $facts
     * @return array{passed: bool, items: list<array{field: string, op: string, value: mixed, actual: mixed, passed: bool}>}
     */
    public static function evaluate(array $conditions, array $facts): array
    {
        $results = [];
        foreach ($conditions['items'] ?? [] as $item) {
            $actual = $facts[$item['field']] ?? null;
            $results[] = [...$item, 'actual' => $actual, 'passed' => self::holds($item['op'], $actual, $item['value'])];
        }
        $passed = array_column($results, 'passed');
        $ok = $passed === [] || (($conditions['match'] ?? 'all') === 'any' ? in_array(true, $passed, true) : ! in_array(false, $passed, true));

        return ['passed' => $ok, 'items' => $results];
    }

    private static function holds(string $op, mixed $actual, mixed $value): bool
    {
        if ($op === 'is_true' || $op === 'is_false') {
            return is_bool($actual) && $actual === ($op === 'is_true');
        }
        // A fact the subject does not have (an order without agreed value) never satisfies a comparison.
        if ($actual === null) {
            return $op === 'neq' || $op === 'not_in';
        }
        $a = is_int($actual) ? $actual : (string) $actual;

        return match ($op) {
            'eq' => is_int($a) ? $a === (int) $value : $a === (string) $value,
            'neq' => is_int($a) ? $a !== (int) $value : $a !== (string) $value,
            'gt' => (int) $a > (int) $value,
            'gte' => (int) $a >= (int) $value,
            'lt' => (int) $a < (int) $value,
            'lte' => (int) $a <= (int) $value,
            'between' => is_array($value) && (int) $a >= (int) $value[0] && (int) $a <= (int) $value[1],
            'in' => is_array($value) && in_array((string) $a, array_map('strval', $value), true),
            'not_in' => is_array($value) && ! in_array((string) $a, array_map('strval', $value), true),
            default => false,
        };
    }
}
