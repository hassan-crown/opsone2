<?php

namespace App\Configuration;

use App\Automation\AutomationText;
use App\Finance\Plans\PaymentPlans;
use App\Models\RoleTemplate;
use App\Support\RequestCache;
use App\Workflow\Catalogue\ActionType;

/**
 * The readable change log between two snapshots (ADR-0040): per section, the items added, removed or changed, and for
 * a change the fields with their before/after as short text. Lists are matched by their natural key (plan, clause and
 * sequence keys; a message by event, channel and language), single sections field by field. Each item also carries a
 * `label` in the interface language (the plan's name, the clause's title…): the key is internal and never shown. Values
 * are text in the interface language; numbers, percentages and dates are wrapped in Unicode isolates (FSI…PDI) so they
 * keep their order inside an Arabic line.
 */
final class ConfigDiff
{
    /** Item key per list section. */
    private const KEYS = [
        'payment_plans' => 'key',
        'clauses' => 'key',
        'messages' => null,
        'numbering' => 'sequence',
        'automation' => 'key',
    ];

    /** Fields never shown: identifiers and counters. */
    private const HIDDEN = ['id', 'key', 'sequence', 'event', 'channel', 'locale', 'version'];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{section: string, item: string|null, label: string|null, change: string, fields: list<array{field: string, before: string|null, after: string|null}>}>
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
                        $changes[] = ['section' => $section, 'item' => (string) $key, 'label' => self::label($section, $item), 'change' => 'added', 'fields' => self::fields($section, [], $item)];
                    } elseif ($oldItems[$key] != $item) {
                        $changes[] = ['section' => $section, 'item' => (string) $key, 'label' => self::label($section, $item), 'change' => 'changed', 'fields' => self::fields($section, $oldItems[$key], $item)];
                    }
                }
                foreach ($oldItems as $key => $item) {
                    if (! isset($newItems[$key])) {
                        $changes[] = ['section' => $section, 'item' => (string) $key, 'label' => self::label($section, $item), 'change' => 'removed', 'fields' => self::fields($section, $item, [])];
                    }
                }

                continue;
            }
            $changes[] = ['section' => $section, 'item' => null, 'label' => null, 'change' => 'changed', 'fields' => self::fields($section, self::flat((array) $old), self::flat((array) $new))];
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

    /**
     * The item's name in the interface language, for the change log heading.
     *
     * @param  array<string, mixed>  $item
     */
    private static function label(string $section, array $item): ?string
    {
        return match ($section) {
            'payment_plans', 'automation' => self::localized($item['name'] ?? null),
            'clauses' => self::localized($item['title'] ?? null),
            'numbering' => self::trans('rules.numbering.seq.'.$item['sequence'], (string) $item['sequence']),
            'messages' => implode(' · ', [
                self::trans('rules.messages.event.'.$item['event'], (string) $item['event']),
                self::trans('rules.messages.channel.'.$item['channel'], (string) $item['channel']),
                self::trans('rules.messages.locale.'.$item['locale'], (string) $item['locale']),
            ]),
            default => null,
        };
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
            return implode(' · ', array_map(fn (array $l) => self::planLine($l), $value));
        }
        if ($section === 'automation' && $field === 'trigger' && is_string($value)) {
            return __('automation.trigger.'.$value);
        }
        if ($section === 'automation' && is_array($value) && in_array($field, ['conditions', 'actions', 'params'], true)) {
            return AutomationText::of($field, $value);
        }
        if ($section === 'reminders' && $field === 'escalate_to' && is_string($value)) {
            return $value === 'order_assignee' ? __('rules.reminders.order_assignee')
                : (app(RequestCache::class)->remember('config-diff.roles', fn () => RoleTemplate::query()->whereNotNull('key')->get()
                    ->mapWithKeys(fn (RoleTemplate $r) => [(string) $r->key => $r->name()])->all())[$value] ?? $value);
        }
        if ($section === 'numbering' && $field === 'year_mode' && is_string($value)) {
            return self::trans('rules.numbering.year.'.$value, $value);
        }
        if ($section === 'task_sla' && $field === 'sla_by_type' && is_array($value)) {
            return self::list(array_map(fn (string $type, mixed $days) => (ActionType::tryFrom($type)?->label()[app()->getLocale()] ?? $type).': '.self::isolate((string) $days),
                array_keys($value), $value));
        }
        if (is_array($value) && array_is_list($value) && array_filter($value, fn ($v) => ! is_scalar($v)) === []) {
            $prefix = match ([$section, $field]) {
                ['clauses', 'applies_to'] => 'rules.clauses.kind.',
                ['field_sales', 'kinds'] => 'appointments.kind.',
                default => null,
            };

            return self::list(array_map(fn ($v) => $prefix === null ? self::isolate((string) $v) : self::trans($prefix.$v, (string) $v), $value));
        }
        if (is_array($value)) {
            if (isset($value['ar']) && is_string($value['ar']) && count($value) <= 2) {
                return self::localized($value);
            }

            return self::isolate(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }

        return is_int($value) || is_float($value) ? self::isolate((string) $value) : (string) $value;
    }

    /**
     * «First payment: 50% at the agreement's approval» in the interface language.
     *
     * @param  array<string, mixed>  $line
     */
    private static function planLine(array $line): string
    {
        $value = PaymentPlans::valueText(['basis' => (string) $line['basis'], 'value' => $line['value'] === null ? null : (int) $line['value']]);
        $share = $value === null ? __('rules.releases.rest') : self::isolate($value.($line['basis'] === 'percent' ? '%' : ''));
        if ($line['event'] === 'stage.started') {
            $stages = app(RequestCache::class)->remember('config-diff.stages', fn () => array_column(PaymentPlans::stageOptions(), 'name', 'key'));
            $stage = $stages[$line['stage_key']] ?? (string) $line['stage_key'];
            $when = __('esign.pdf.due.stage.started', ['stage' => $stage]);
        } else {
            $when = self::trans('finance.event.'.$line['event'], (string) $line['event']);
        }
        if ((int) $line['offset_days'] > 0) {
            $when = __('finance.plans.offset', ['when' => $when, 'days' => self::isolate((string) $line['offset_days'])]);
        }
        $name = self::localized($line['name'] ?? null) ?? (string) $line['key'];

        return "{$name}: {$share} {$when}";
    }

    /**
     * @param  list<string>  $items
     */
    private static function list(array $items): string
    {
        return implode(app()->getLocale() === 'ar' ? '، ' : ', ', $items);
    }

    /** One `{ar, en}` value in the interface language (Arabic when the English is empty). */
    private static function localized(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return null;
        }
        $text = $value[app()->getLocale()] ?? null;

        return is_string($text) && $text !== '' ? $text : (is_string($value['ar'] ?? null) ? $value['ar'] : null);
    }

    private static function trans(string $key, string $fallback): string
    {
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : $fallback;
    }

    /** First-strong isolate: a number or code keeps its own direction inside a sentence of the other one. */
    private static function isolate(string $text): string
    {
        return "\u{2068}{$text}\u{2069}";
    }
}
