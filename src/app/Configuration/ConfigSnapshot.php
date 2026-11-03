<?php

namespace App\Configuration;

use App\Models\Agreements\DocumentClause;
use App\Models\Finance\PaymentPlan;
use App\Models\MessageTemplate;
use App\Models\NumberFormat;
use App\Models\ReminderSettings;
use App\Models\WorkSettings;
use App\Procurement\PurchaseDocumentLayout;

/**
 * The business rules as one typed snapshot (ADR-0040). The phase-1 tables are the draft: `capture()` reads them as
 * they are now; a release stores what `capture()` returned when it was published, and the application reads the
 * release, never the draft. `defaults()` is what applies before the first release: the built-in rules, exactly as
 * empty phase-1 tables behaved.
 *
 * Sections: payment_plans, reminders, clauses, messages, task_sla, numbering, purchase_document. WhatsApp template
 * names stay immediate (they must match what Meta approved) and the flow keeps its own versions (M-23).
 */
final class ConfigSnapshot
{
    public const SCHEMA = 1;

    public const SECTIONS = ['payment_plans', 'reminders', 'clauses', 'messages', 'task_sla', 'numbering', 'purchase_document'];

    /**
     * @return array<string, mixed>
     */
    public static function capture(): array
    {
        $work = WorkSettings::current();
        $reminders = ReminderSettings::working();

        return [
            'schema' => self::SCHEMA,
            'payment_plans' => array_values(PaymentPlan::query()->active()->orderBy('key')->get()->map(fn (PaymentPlan $p) => [
                'id' => $p->id, 'key' => $p->key, 'name' => $p->name->toArray(), 'is_default' => $p->is_default, 'lines' => $p->lines,
            ])->all()),
            'reminders' => self::reminders($reminders),
            'clauses' => array_values(DocumentClause::query()->whereNull('archived_at')->orderBy('key')->get()->map(fn (DocumentClause $c) => [
                'id' => $c->id, 'key' => $c->key, 'version' => $c->version, 'title' => $c->title->toArray(), 'body' => $c->body->toArray(),
                'applies_to' => $c->applies_to, 'included_by_default' => $c->included_by_default, 'position' => $c->position,
            ])->all()),
            'messages' => array_values(MessageTemplate::query()->orderBy('event')->orderBy('channel')->orderBy('locale')->get()->map(fn (MessageTemplate $m) => [
                'event' => $m->event, 'channel' => $m->channel, 'locale' => $m->locale, 'subject' => $m->subject, 'body' => $m->body,
            ])->all()),
            'task_sla' => [
                'task_sla' => $work->task_sla,
                'task_escalate_after_days' => (int) ($work->task_escalate_after_days ?? 1),
                'holidays' => $work->holidays ?? [],
            ],
            'numbering' => array_values(NumberFormat::query()->orderBy('sequence')->get()->map(fn (NumberFormat $f) => [
                'sequence' => $f->sequence, 'prefix' => $f->prefix, 'year_mode' => $f->year_mode, 'per_branch' => $f->per_branch,
                'pad' => $f->pad, 'start_at' => $f->start_at, 'start_period' => $f->start_period,
            ])->all()),
            'purchase_document' => PurchaseDocumentLayout::draft(),
        ];
    }

    /**
     * The rules before any release: what the application did with empty phase-1 tables.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'schema' => self::SCHEMA,
            'payment_plans' => [],
            'reminders' => self::reminders(new ReminderSettings(ReminderSettings::DEFAULTS)),
            'clauses' => [],
            'messages' => [],
            'task_sla' => ['task_sla' => null, 'task_escalate_after_days' => 1, 'holidays' => []],
            'numbering' => [],
            'purchase_document' => ['version' => 0, 'layout' => PurchaseDocumentLayout::defaultLayout(), 'email' => PurchaseDocumentLayout::defaultEmail()],
        ];
    }

    /**
     * The snapshot as compared between draft and release: the purchase-document version number is a counter of saves,
     * not content (a restore writes the same content as a new version).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function comparable(array $snapshot): array
    {
        unset($snapshot['purchase_document']['version']);

        return self::canonical($snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::comparable($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Objects with sorted keys (lists keep their order), so equal content always hashes the same.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function reminders(ReminderSettings $settings): array
    {
        return [
            'before_days' => array_map(intval(...), $settings->before_days ?? []),
            'on_due' => (bool) $settings->on_due,
            'after_every_days' => (int) $settings->after_every_days,
            'max_after' => (int) $settings->max_after,
            'escalate_after_days' => $settings->escalate_after_days === null ? null : (int) $settings->escalate_after_days,
            'escalate_to' => $settings->escalate_to,
            'appointment_hours' => (int) $settings->appointment_hours,
        ];
    }
}
