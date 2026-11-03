<?php

namespace App\Configuration;

use App\Agreements\DocumentClauses;
use App\Finance\Plans\PaymentPlans;
use App\Messages\MessageCatalogue;
use App\Messages\MessageTemplates;
use App\Numbering\NumberFormats;
use App\Workflow\AppointmentBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The bundle check before a snapshot is published (ADR-0040). Each draft table was validated when it was saved; this
 * re-checks what may have moved since and what ties the sections together: payment plans against the published flow
 * (stage triggers, the references its `due` gates wait for, one default), unique number prefixes and starting numbers
 * above the counters, message and clause placeholders. Errors are keyed `section.item.field` with a readable message.
 */
final class ConfigCheck
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $published
     */
    public static function assert(array $snapshot, array $published): void
    {
        $errors = self::errors($snapshot, $published);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $published
     * @return array<string, string>
     */
    public static function errors(array $snapshot, array $published): array
    {
        $errors = [];
        $plans = $snapshot['payment_plans'] ?? [];
        $stages = PaymentPlans::stageKeys();
        $gates = PaymentPlans::gateReferences();
        if ($plans !== [] && count(array_filter($plans, fn (array $p) => $p['is_default'])) !== 1) {
            $errors['payment_plans'] = __('rules.releases.check.one_default');
        }
        foreach ($plans as $plan) {
            $path = "payment_plans.{$plan['key']}";
            try {
                PaymentPlans::check($plan['lines']);
            } catch (ValidationException $e) {
                $errors[$path] = (string) collect($e->errors())->flatten()->first();

                continue;
            }
            foreach ($plan['lines'] as $line) {
                if ($line['stage_key'] !== null && ! in_array($line['stage_key'], $stages, true)) {
                    $errors[$path] = __('rules.releases.check.stage', ['plan' => $plan['key'], 'stage' => $line['stage_key']]);
                }
            }
            $missing = array_diff($gates, array_column($plan['lines'], 'key'));
            if ($missing !== []) {
                $errors[$path] = __('finance.plans.validation.gate_reference', ['reference' => implode(', ', $missing)]);
            }
        }
        if (count($plans) > PaymentPlans::MAX_ACTIVE) {
            $errors['payment_plans'] = __('rules.releases.check.too_many', ['max' => PaymentPlans::MAX_ACTIVE]);
        }

        foreach ($snapshot['clauses'] ?? [] as $clause) {
            foreach (['ar', 'en'] as $locale) {
                try {
                    DocumentClauses::checkVariables((string) ($clause['body'][$locale] ?? ''), "clauses.{$clause['key']}");
                } catch (ValidationException $e) {
                    $errors["clauses.{$clause['key']}"] = (string) collect($e->errors())->flatten()->first();
                }
            }
        }

        foreach ($snapshot['messages'] ?? [] as $message) {
            $definition = MessageCatalogue::get($message['event']);
            $path = "messages.{$message['event']}.{$message['channel']}.{$message['locale']}";
            if ($definition === null) {
                $errors[$path] = __('rules.releases.check.message_unknown', ['event' => $message['event']]);

                continue;
            }
            try {
                MessageTemplates::checkVariables($message['body'].' '.($message['subject'] ?? ''), $definition['variables'], $path);
            } catch (ValidationException $e) {
                $errors[$path] = (string) collect($e->errors())->flatten()->first();
            }
        }

        $prefixes = [];
        foreach (NumberFormats::SEQUENCES as $sequence => $default) {
            $prefixes[$sequence] = $default['prefix'];
        }
        $formats = [];
        foreach ($snapshot['numbering'] ?? [] as $format) {
            $prefixes[$format['sequence']] = $format['prefix'];
            $formats[$format['sequence']] = $format;
        }
        foreach (array_count_values($prefixes) as $prefix => $count) {
            if ($count > 1 || in_array($prefix, NumberFormats::RESERVED, true)) {
                $errors['numbering'] = __('rules.releases.check.prefix', ['prefix' => $prefix]);
            }
        }
        $publishedStarts = array_column($published['numbering'] ?? [], 'start_at', 'sequence');
        foreach ($formats as $sequence => $format) {
            if ($format['start_at'] === null || $format['start_at'] === ($publishedStarts[$sequence] ?? null)) {
                continue;
            }
            $period = NumberFormats::period((string) $format['year_mode'], now(AppointmentBooking::timezone()));
            $current = (int) DB::table('sequence_counters')->where('name', $sequence)->where('period', $period)->value('last_value');
            if ($format['start_period'] === $period && (int) $format['start_at'] <= $current) {
                $errors["numbering.{$sequence}"] = __('rules.numbering.validation.start_below', ['current' => $current]);
            }
        }

        return $errors;
    }
}
