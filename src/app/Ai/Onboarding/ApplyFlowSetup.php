<?php

namespace App\Ai\Onboarding;

use App\Actions\Rules\RulesAccess;
use App\Actions\Rules\SaveAutomationRule;
use App\Actions\Rules\SavePaymentPlan;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Ai\AiSuggestions;
use App\Ai\AiSuggestionStatus;
use App\Enums\FlowStatus;
use App\Finance\Plans\PaymentPlans;
use App\Models\Ai\AiFlowSetup;
use App\Models\Ai\AiSuggestion;
use App\Models\Automation\AutomationRule;
use App\Models\Finance\PaymentPlan;
use App\Models\Flow;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «اعتمد كمسودة» (AI-1): writes an accepted proposal as DRAFTS through the existing Actions, in one transaction —
 * the flow draft (M-23-T02, P-20), the payment plan as the draft default (SavePaymentPlan) and the chosen rules
 * switched off (SaveAutomationRule). Nothing is published: the specialist reviews the flow in the editor, runs
 * FlowCheck, publishes it, then tries the rules and publishes the configuration release (ConfigCheck).
 *
 * An existing flow draft (possibly edited by hand) is replaced only with `$replaceDraft`. P-104 only (the Actions
 * re-authorize their own permission). The proposal is checked again here, inside the transaction with the flow and
 * the draft rule tables locked — FlowCheck and the configuration checks against the proposed flow — and refused while
 * anything fails. A rule whose key already exists
 * is never replaced; it is reported as skipped.
 */
final class ApplyFlowSetup
{
    public const SOURCE = 'ai_suggestion';

    public function __construct(
        private readonly FlowSetupCheck $check,
        private readonly T02SaveDraft $saveDraft,
        private readonly SavePaymentPlan $savePlan,
        private readonly SaveAutomationRule $saveRule,
        private readonly AiSuggestions $suggestions,
    ) {}

    /**
     * @param  list<string>  $ruleKeys  the proposed rules to create (keys from the payload)
     * @return array{plan: string|null, rules: list<string>, skipped: list<string>}
     */
    public function handle(User $actor, AiFlowSetup $setup, bool $includePlan, array $ruleKeys, int $flowLockVersion, bool $replaceDraft = false): array
    {
        RulesAccess::authorize($actor);

        return DB::transaction(function () use ($actor, $setup, $includePlan, $ruleKeys, $flowLockVersion, $replaceDraft) {
            // Lock what the check reads and the write changes — the request, the flow and the draft rule tables — so
            // nothing moves between the check and the write.
            $locked = AiFlowSetup::query()->lockForUpdate()->findOrFail($setup->id);
            $suggestion = $locked->ai_suggestion_id === null ? null : AiSuggestion::query()->lockForUpdate()->find($locked->ai_suggestion_id);
            if ($locked->status !== AiFlowSetup::READY || $suggestion === null || $suggestion->status !== AiSuggestionStatus::Proposed
                || ($suggestion->expires_at !== null && $suggestion->expires_at->isPast())) {
                throw ValidationException::withMessages(['setup' => __('ai_setup.errors.not_open')]);
            }

            $current = Flow::query()->lockForUpdate()->orderBy('id')->first();
            if ($current !== null && $current->lock_version !== $flowLockVersion) {
                throw new StaleRecord($current->lock_version);
            }
            // A hand-made draft is never replaced silently: the specialist confirms it on the screen.
            if ($current !== null && $current->status === FlowStatus::PublishedWithDraft && ! $replaceDraft) {
                throw ValidationException::withMessages(['replace_draft' => __('ai_setup.errors.draft_exists')]);
            }
            PaymentPlan::query()->lockForUpdate()->get(['id']);
            AutomationRule::query()->lockForUpdate()->get(['id']);

            $payload = $suggestion->payload;
            $final = self::choose($payload, $includePlan, $ruleKeys);
            $checks = $this->check->run($final);
            if (! $checks['passes'] || $checks['flow'] === null) {
                $messages = [];
                foreach ($checks['flow_issues'] as $issue) {
                    $messages['flow.'.$issue['path']][] = $issue['message'];
                }
                foreach ($checks['config_errors'] as $path => $message) {
                    $messages['config.'.$path][] = $message;
                }

                throw ValidationException::withMessages($messages ?: ['setup' => __('ai_setup.errors.checks_failed')]);
            }
            $flow = $checks['flow'];

            $this->saveDraft->handle($actor, $flow, $flowLockVersion, self::SOURCE);

            $planKey = null;
            $created = [];
            $skipped = [];
            PaymentPlans::assumingFlow($flow['stages'], function () use ($actor, $final, &$planKey, &$created, &$skipped) {
                $plan = $final['payment_plan'] ?? null;
                if (is_array($plan)) {
                    $existing = PaymentPlan::query()->active()->where('key', $plan['key'])->first();
                    $this->savePlan->handle($actor, $existing, $plan, $existing?->lock_version, self::SOURCE);
                    $planKey = (string) $plan['key'];
                }
                foreach ($final['automation'] as $rule) {
                    if (AutomationRule::query()->where('key', $rule['key'])->exists()) {
                        $skipped[] = (string) $rule['key'];

                        continue;
                    }
                    // Always off: switching a rule on is a separate, manual step after the simulation.
                    $this->saveRule->handle($actor, null, [...$rule, 'enabled' => false], null, self::SOURCE);
                    $created[] = (string) $rule['key'];
                }
            });

            $this->accept($suggestion, $actor, $payload, $final);
            $locked->forceFill(['status' => AiFlowSetup::APPLIED, 'decided_by' => $actor->id, 'decided_at' => now()])->save();

            return ['plan' => $planKey, 'rules' => $created, 'skipped' => $skipped];
        });
    }

    public function reject(User $actor, AiFlowSetup $setup): void
    {
        RulesAccess::authorize($actor);
        DB::transaction(function () use ($actor, $setup) {
            $locked = AiFlowSetup::query()->lockForUpdate()->findOrFail($setup->id);
            if (! $locked->isOpen()) {
                return;
            }
            $suggestion = FlowDraftProposer::suggestionOf($locked);
            if ($suggestion !== null && $suggestion->status === AiSuggestionStatus::Proposed) {
                $this->suggestions->reject($suggestion, $actor);
            }
            $locked->forceFill(['status' => AiFlowSetup::REJECTED, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
        });
    }

    /**
     * The proposal reduced to what the specialist kept: always the flow; the plan and each rule by choice.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $ruleKeys
     * @return array<string, mixed>
     */
    public static function choose(array $payload, bool $includePlan, array $ruleKeys): array
    {
        unset($payload['checks']);
        $payload['payment_plan'] = $includePlan ? ($payload['payment_plan'] ?? null) : null;
        $payload['automation'] = array_values(array_filter((array) ($payload['automation'] ?? []), fn (array $r) => in_array($r['key'], $ruleKeys, true)));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $final
     */
    private function accept(AiSuggestion $suggestion, User $actor, array $payload, array $final): void
    {
        $original = $payload;
        unset($original['checks']);
        $this->suggestions->accept($suggestion, $actor, $final == $original ? null : $final);
    }
}
