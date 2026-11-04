<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Automation\AutomationRules;
use App\Configuration\ConfigReleases;
use App\Models\Automation\AutomationRule;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drafts, archives, kills and revives automation rules (P-104, ADR-0042). Saving and archiving change the draft (they
 * act once published as a configuration release); the kill switch is live: a killed rule is skipped at once in every
 * release, including those orders pinned, until it is revived. Every change is audited.
 */
final class SaveAutomationRule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, ?AutomationRule $rule, array $data, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): AutomationRule
    {
        RulesAccess::authorize($actor);
        $definition = AutomationRules::normalize($data);

        return DB::transaction(function () use ($actor, $rule, $definition, $lockVersion, $source, $data) {
            if ($rule !== null) {
                $rule = AutomationRule::query()->lockForUpdate()->findOrFail($rule->id);
                if ($lockVersion !== null && $lockVersion !== $rule->lock_version) {
                    throw new StaleRecord($rule->lock_version);
                }
            } elseif (AutomationRule::query()->active()->count() >= AutomationRules::MAX_RULES) {
                throw ValidationException::withMessages(['key' => __('automation.validation.too_many_rules', ['max' => AutomationRules::MAX_RULES])]);
            }
            if (AutomationRule::query()->where('key', $definition['key'])->when($rule !== null, fn ($q) => $q->whereKeyNot($rule->id))->exists()) {
                throw ValidationException::withMessages(['key' => __('automation.validation.key_taken')]);
            }
            $before = $rule?->definition() ?? [];
            $rule ??= new AutomationRule;
            $rule->forceFill([
                'key' => $definition['key'], 'name' => $definition['name'], 'trigger' => $definition['trigger'],
                'trigger_params' => $definition['params'] === [] ? null : $definition['params'],
                'conditions' => $definition['conditions'], 'actions' => $definition['actions'], 'enabled' => $definition['enabled'],
                'position' => (int) ($data['position'] ?? $rule->position ?? AutomationRule::query()->max('position') + 1),
                'archived_at' => null,
                'lock_version' => $rule->exists ? $rule->lock_version + 1 : 1, 'updated_by' => $actor->id,
            ])->save();
            Audit::record(AuditAction::AutomationRuleSaved, $rule, $actor, before: $before, after: $rule->definition(), source: $source, label: $rule->key);
            ConfigReleases::forget();

            return $rule;
        });
    }

    public function archive(User $actor, AutomationRule $rule, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        RulesAccess::authorize($actor);
        DB::transaction(function () use ($actor, $rule, $source) {
            $rule = AutomationRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->archived_at !== null) {
                return;
            }
            $rule->forceFill(['archived_at' => now(), 'lock_version' => $rule->lock_version + 1, 'updated_by' => $actor->id])->save();
            Audit::record(AuditAction::AutomationRuleArchived, $rule, $actor, before: ['key' => $rule->key], source: $source, label: $rule->key);
        });
    }

    /**
     * The kill switch: stops the rule now, in every release, with a reason.
     */
    public function kill(User $actor, AutomationRule $rule, string $reason, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        RulesAccess::authorize($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('automation.validation.kill_reason')]);
        }
        DB::transaction(function () use ($actor, $rule, $reason, $source) {
            $rule = AutomationRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->killed_at !== null) {
                return;
            }
            $rule->forceFill(['killed_at' => now(), 'killed_by' => $actor->id, 'kill_reason' => $reason])->save();
            Audit::record(AuditAction::AutomationRuleKilled, $rule, $actor, after: ['key' => $rule->key], reason: $reason, source: $source, label: $rule->key);
        });
    }

    public function revive(User $actor, AutomationRule $rule, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        RulesAccess::authorize($actor);
        DB::transaction(function () use ($actor, $rule, $source) {
            $rule = AutomationRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->killed_at === null) {
                return;
            }
            $before = ['killed_at' => $rule->killed_at->toIso8601String(), 'kill_reason' => $rule->kill_reason];
            $rule->forceFill(['killed_at' => null, 'killed_by' => null, 'kill_reason' => null])->save();
            Audit::record(AuditAction::AutomationRuleRevived, $rule, $actor, before: $before, source: $source, label: $rule->key);
        });
    }
}
