<?php

namespace App\Configuration;

use App\Agreements\DocumentClauses;
use App\Finance\OrderSchedule;
use App\Finance\Plans\PaymentPlans;
use App\Messages\MessageCatalogue;
use App\Messages\MessageTemplates;
use App\Money\Money;

/**
 * «Try it» before publishing (ADR-0040): the draft and the current release side by side on sample data — each payment
 * plan applied to an agreed value (amounts in halalas, the `rest` absorbing rounding exactly as an order would), the
 * default clauses of a quote and a contract rendered with sample values, and every changed message text rendered with
 * its event's sample values. Pure: reads snapshots, writes nothing, sends nothing.
 */
final class ConfigPreview
{
    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $published
     * @return array<string, mixed>
     */
    public static function run(array $draft, array $published, Money $value, string $locale): array
    {
        return [
            'value_minor' => $value->minor,
            'plans' => [
                'draft' => self::plans($draft, $value, $locale),
                'published' => self::plans($published, $value, $locale),
            ],
            'clauses' => [
                'draft' => self::clauses($draft, $locale),
                'published' => self::clauses($published, $locale),
            ],
            'messages' => self::messages($draft, $published, $locale),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private static function plans(array $snapshot, Money $value, string $locale): array
    {
        $plans = $snapshot['payment_plans'] ?? [];
        if ($plans === []) {
            // No plan: orders get the built-in fallback.
            $fallback = PaymentPlans::TEMPLATES[PaymentPlans::FALLBACK];
            $plans = [['key' => PaymentPlans::FALLBACK, 'name' => $fallback['name'], 'is_default' => true, 'lines' => $fallback['lines'], 'builtin' => true]];
        }

        $stages = array_column(PaymentPlans::stageOptions(), 'name', 'key');

        return array_values(array_map(function (array $plan) use ($value, $locale, $stages) {
            $amounts = OrderSchedule::preview($plan['lines'], $value);

            return [
                'key' => $plan['key'],
                'name' => (string) ($plan['name'][$locale] ?? null ?: $plan['name']['ar']),
                'is_default' => (bool) $plan['is_default'],
                'builtin' => (bool) ($plan['builtin'] ?? false),
                'dues' => array_map(fn (array $line, int $amount) => [
                    'key' => $line['key'],
                    'name' => (string) ($line['name'][$locale] ?? null ?: $line['name']['ar']),
                    'share' => PaymentPlans::valueText(['basis' => (string) $line['basis'], 'value' => $line['value'] === null ? null : (int) $line['value']]),
                    'basis' => $line['basis'],
                    'event' => $line['event'],
                    'stage_key' => $line['stage_key'],
                    'stage' => $line['stage_key'] === null ? null : ($stages[$line['stage_key']] ?? (string) $line['stage_key']),
                    'offset_days' => (int) $line['offset_days'],
                    'grace_days' => (int) $line['grace_days'],
                    'amount_minor' => $amount,
                ], $plan['lines'], $amounts),
                'total_minor' => array_sum($amounts),
            ];
        }, $plans));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, list<string>>
     */
    private static function clauses(array $snapshot, string $locale): array
    {
        $samples = DocumentClauses::samples($locale);
        /** @var list<array<string, mixed>> $clauses */
        $clauses = $snapshot['clauses'] ?? [];
        $out = [];
        foreach (DocumentClauses::KINDS as $kind) {
            $out[$kind] = array_values(collect($clauses)
                ->filter(fn (array $c) => $c['included_by_default'] && in_array($kind, $c['applies_to'], true))
                ->sortBy([['position', 'asc'], ['id', 'asc']])
                ->map(fn (array $c) => DocumentClauses::render($locale === 'en' && ($c['body']['en'] ?? '') !== '' ? (string) $c['body']['en'] : (string) $c['body']['ar'], $samples))
                ->all());
        }

        return $out;
    }

    /**
     * Message texts that differ between the release and the draft, in the chosen language.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $published
     * @return list<array<string, mixed>>
     */
    private static function messages(array $draft, array $published, string $locale): array
    {
        /** @param  array<string, mixed>  $snapshot */
        $index = fn (array $snapshot) => collect((array) ($snapshot['messages'] ?? []))->filter(fn (array $m) => $m['locale'] === $locale)
            ->keyBy(fn (array $m) => "{$m['event']}|{$m['channel']}")->all();
        $new = $index($draft);
        $old = $index($published);
        $samples = MessageCatalogue::samples($locale);
        $out = [];
        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $key) {
            if (($new[$key] ?? null) == ($old[$key] ?? null)) {
                continue;
            }
            [$event, $channel] = explode('|', $key);
            $render = fn (?array $m) => $m === null ? null : [
                'subject' => $m['subject'] === null ? null : MessageTemplates::fill($m['subject'], $samples),
                'body' => MessageTemplates::fill($m['body'], $samples),
            ];
            $out[] = ['event' => $event, 'channel' => $channel, 'draft' => $render($new[$key] ?? null), 'published' => $render($old[$key] ?? null)];
        }

        return $out;
    }
}
