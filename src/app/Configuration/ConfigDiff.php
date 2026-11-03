<?php

namespace App\Configuration;

use App\Finance\Plans\PaymentPlans;

/**
 * The readable change log between two snapshots (ADR-0040): per section, the items added, removed or changed, and for
 * a change the fields with their before/after as short text. Lists are matched by their natural key (plan, clause and
 * sequence keys; a message by event, channel and language), single sections field by field.
 */
final class ConfigDiff
{
    /** Item key per list section. */
    private const KEYS = [
        'payment_plans' => 'key',
        'clauses' => 'key',
        'messages' => null,
        'numbering' => 'sequence',
    ];

    /** Fields never shown: identifiers and counters. */
    private const HIDDEN = ['id', 'key', 'sequence', 'event', 'channel', 'locale', 'version'];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{section: string, item: string|null, change: string, fields: list<array{field: string, before: string|null, after: string|null}>}>
     */
    public static function between(array $before, array $after): array
    {
        $before = ConfigSnapshot::comparable($before);
        $after = ConfigSnapshot::comparable($after);
        $changes = [];
        foreach (ConfigSnapshot::SECTIONS as $section) {
            $old = $before[$section] ?? null;
            $new = $after[$section] ?? null;
            if ($old == $new) {
                continue;
            }
            if (array_key_exists($section, self::KEYS)) {
                $oldItems = self::keyed($section, (array) $old);
                $newItems = self::keyed($section, (array) $new);
                foreach ($newItems as $key => $item) {
                    if (! isset($oldItems[$key])) {
                        $changes[] = ['section' => $section, 'item' => (string) $key, 'change' => 'added', 'fields' => self::fields($section, [], $item)];
                    } elseif ($oldItems[$key] != $item) {
                        $changes[] = ['section' => $section, 'item' => (string) $key, 'change' => 'changed', 'fields' => self::fields($section, $oldItems[$key], $item)];
                    }
                }
                foreach ($oldItems as $key => $item) {
                    if (! isset($newItems[$key])) {
                        $changes[] = ['section' => $section, 'item' => (string) $key, 'change' => 'removed', 'fields' => self::fields($section, $item, [])];
                    }
                }

                continue;
            }
            $changes[] = ['section' => $section, 'item' => null, 'change' => 'changed', 'fields' => self::fields($section, self::flat((array) $old), self::flat((array) $new))];
        }

        return $changes;
    }

    /**
     * Counts per section, for the audit trail and the release list.
     *
     * @param  list<array{section: string}>  $changes
     * @return array<string, int>
     */
    public static function summary(array $changes): array
    {
        $out = [];
        foreach ($changes as $change) {
            $out[$change['section']] = ($out[$change['section']] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $items
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(string $section, array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $item = (array) $item;
            $key = self::KEYS[$section] === null ? "{$item['event']} · {$item['channel']} · {$item['locale']}" : (string) $item[self::KEYS[$section]];
            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * Singletons with nested settings (task SLA) compare their inner fields one by one.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function flat(array $values): array
    {
        if (isset($values['task_sla']) && is_array($values['task_sla'])) {
            foreach ($values['task_sla'] as $k => $v) {
                $values["sla_{$k}"] = $v;
            }
        }
        unset($values['task_sla']);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{field: string, before: string|null, after: string|null}>
     */
    private static function fields(string $section, array $old, array $new): array
    {
        $out = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            if (in_array($field, self::HIDDEN, true)) {
                continue;
            }
            $a = $old[$field] ?? null;
            $b = $new[$field] ?? null;
            if ($old !== [] && $new !== [] && $a == $b) {
                continue;
            }
            $out[] = ['field' => (string) $field, 'before' => $old === [] ? null : self::text($section, (string) $field, $a), 'after' => $new === [] ? null : self::text($section, (string) $field, $b)];
        }

        return $out;
    }

    private static function text(string $section, string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '✓' : '✗';
        }
        if ($section === 'payment_plans' && $field === 'lines' && is_array($value)) {
            return implode(' · ', array_map(fn (array $l) => trim(sprintf('%s %s%s → %s%s%s',
                $l['name']['ar'] ?? $l['key'],
                PaymentPlans::valueText(['basis' => (string) $l['basis'], 'value' => $l['value'] === null ? null : (int) $l['value']]) ?? __('rules.releases.rest'),
                $l['basis'] === 'percent' ? '%' : '',
                $l['event'],
                $l['stage_key'] !== null ? ':'.$l['stage_key'] : '',
                (int) $l['offset_days'] > 0 ? ' +'.$l['offset_days'] : '',
            )), $value));
        }
        if (is_array($value)) {
            if (array_is_list($value) && array_filter($value, fn ($v) => ! is_scalar($v)) === []) {
                return implode(', ', array_map(fn ($v) => (string) $v, $value));
            }
            if (isset($value['ar']) && is_string($value['ar']) && count($value) <= 2) {
                return trim($value['ar'].(isset($value['en']) && $value['en'] !== '' ? ' / '.$value['en'] : ''));
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return (string) $value;
    }
}
