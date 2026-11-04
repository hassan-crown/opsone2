<?php

namespace App\Configuration;

use App\Models\Agreements\DocumentClause;
use App\Models\Agreements\DocumentClauseVersion;
use App\Models\FieldSales\FieldSalesSettings;
use App\Models\Finance\PaymentPlan;
use App\Models\MessageTemplate;
use App\Models\NumberFormat;
use App\Models\Procurement\PurchaseDocumentVersion;
use App\Models\ReminderSettings;
use App\Models\User;
use App\Models\WorkSettings;
use App\Procurement\PurchaseDocumentLayout;

/**
 * Writes a snapshot back into the draft tables (ADR-0040): discarding a draft restores the current release, a rollback
 * restores an older one before publishing it as a new release. Rows are updated in place and never deleted where
 * something may point at them: plans and clauses not in the snapshot are archived, a clause returns to the version the
 * snapshot names (its immutable versions stay), the purchase-order document gets a new version with the old content.
 * Call inside the caller's transaction; the caller audits.
 */
final class ConfigRestorer
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(array $snapshot, User $actor): void
    {
        $this->plans($snapshot['payment_plans'] ?? [], $actor);
        $this->reminders($snapshot['reminders'] ?? ConfigSnapshot::defaults()['reminders'], $actor);
        $this->clauses($snapshot['clauses'] ?? [], $actor);
        $this->messages($snapshot['messages'] ?? [], $actor);
        $this->taskSla($snapshot['task_sla'] ?? ConfigSnapshot::defaults()['task_sla'], $actor);
        $this->numbering($snapshot['numbering'] ?? [], $actor);
        $this->purchaseDocument($snapshot['purchase_document'] ?? ConfigSnapshot::defaults()['purchase_document'], $actor);
        $this->fieldSales($snapshot['field_sales'] ?? ConfigSnapshot::defaults()['field_sales'], $actor);
        ConfigReleases::forget();
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function plans(array $plans, User $actor): void
    {
        $keep = [];
        PaymentPlan::query()->where('is_default', true)->update(['is_default' => false]);
        foreach ($plans as $p) {
            $plan = PaymentPlan::query()->lockForUpdate()->whereKey($p['id'])->first() ?? PaymentPlan::query()->lockForUpdate()->where('key', $p['key'])->first() ?? new PaymentPlan;
            $plan->forceFill([
                'key' => $p['key'], 'name' => $p['name'], 'lines' => $p['lines'], 'is_default' => (bool) $p['is_default'], 'archived_at' => null,
                'lock_version' => $plan->exists ? $plan->lock_version + 1 : 1, 'updated_by' => $actor->id,
            ])->save();
            $keep[] = $plan->id;
        }
        PaymentPlan::query()->active()->whereNotIn('id', $keep)->get()->each(fn (PaymentPlan $plan) => $plan->forceFill([
            'archived_at' => now(), 'is_default' => false, 'lock_version' => $plan->lock_version + 1, 'updated_by' => $actor->id,
        ])->save());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function reminders(array $values, User $actor): void
    {
        $settings = ReminderSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new ReminderSettings;
        $settings->forceFill([...$values, 'lock_version' => $settings->exists ? $settings->lock_version + 1 : 1, 'updated_by' => $actor->id])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $clauses
     */
    private function clauses(array $clauses, User $actor): void
    {
        $keep = [];
        foreach ($clauses as $c) {
            $clause = DocumentClause::query()->lockForUpdate()->whereKey($c['id'])->first() ?? DocumentClause::query()->lockForUpdate()->where('key', $c['key'])->first() ?? new DocumentClause;
            $known = $clause->exists && DocumentClauseVersion::query()->where('document_clause_id', $clause->id)->where('version', $c['version'])->exists();
            $version = $known ? (int) $c['version'] : ($clause->exists ? (int) DocumentClauseVersion::query()->where('document_clause_id', $clause->id)->max('version') + 1 : 1);
            $clause->forceFill([
                'key' => $c['key'], 'title' => $c['title'], 'body' => $c['body'], 'applies_to' => $c['applies_to'],
                'included_by_default' => (bool) $c['included_by_default'], 'position' => (int) $c['position'], 'version' => $version, 'archived_at' => null,
                'lock_version' => $clause->exists ? $clause->lock_version + 1 : 1, 'updated_by' => $actor->id,
            ])->save();
            if (! $known) {
                DocumentClauseVersion::query()->create([
                    'document_clause_id' => $clause->id, 'version' => $version, 'title' => $c['title'], 'body' => $c['body'],
                    'created_by' => $actor->id, 'created_at' => now(),
                ]);
            }
            $keep[] = $clause->id;
        }
        DocumentClause::query()->whereNull('archived_at')->whereNotIn('id', $keep)->get()->each(fn (DocumentClause $clause) => $clause->forceFill([
            'archived_at' => now(), 'lock_version' => $clause->lock_version + 1, 'updated_by' => $actor->id,
        ])->save());
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function messages(array $messages, User $actor): void
    {
        MessageTemplate::query()->delete();
        foreach ($messages as $m) {
            MessageTemplate::query()->create([...$m, 'updated_by' => $actor->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function taskSla(array $values, User $actor): void
    {
        $settings = WorkSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new WorkSettings(WorkSettings::DEFAULTS);
        $settings->forceFill([
            'task_sla' => $values['task_sla'], 'task_escalate_after_days' => (int) $values['task_escalate_after_days'],
            'holidays' => $values['holidays'], 'updated_by' => $actor->id,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fieldSales(array $values, User $actor): void
    {
        $settings = FieldSalesSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new FieldSalesSettings;
        $settings->forceFill([
            'enabled' => (bool) $values['enabled'], 'kinds' => array_values($values['kinds']), 'response_hours' => $values['response_hours'],
            'max_discount_bp' => (int) ($values['max_discount_bp'] ?? 0),
            'updated_by' => $actor->id, 'lock_version' => ($settings->exists ? $settings->lock_version : 0) + 1,
        ])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $formats
     */
    private function numbering(array $formats, User $actor): void
    {
        $sequences = array_column($formats, 'sequence');
        NumberFormat::query()->whereNotIn('sequence', $sequences)->delete();
        foreach ($formats as $f) {
            NumberFormat::query()->updateOrCreate(['sequence' => $f['sequence']], [...$f, 'updated_by' => $actor->id]);
        }
    }

    /**
     * @param  array{layout: array<string, mixed>, email: array<string, mixed>}  $document
     */
    private function purchaseDocument(array $document, User $actor): void
    {
        $draft = PurchaseDocumentLayout::draft();
        if ($draft['layout'] == $document['layout'] && $draft['email'] == $document['email']) {
            return;
        }
        $latest = (int) PurchaseDocumentVersion::query()->lockForUpdate()->max('version');
        PurchaseDocumentVersion::query()->create([
            'version' => $latest + 1, 'layout' => $document['layout'], 'email' => $document['email'], 'created_by' => $actor->id,
        ]);
    }
}
