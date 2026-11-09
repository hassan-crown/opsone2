<?php

namespace App\Ai\Onboarding;

use App\Ai\AiCall;
use App\Ai\Contracts\AiEvalBuilder;
use App\Models\Flow;

/**
 * Evaluation examples of axis 1 (tests/AiEval/fixtures/flow.jsonl, plan §3.6): each line holds only the owner's
 * description (and optional base template / answers); the call is built by FlowDraftProposer exactly as in
 * production, so the set always evaluates the current prompt, schema and facts. `expect.flow_check: true` also maps
 * the answer and runs FlowCheck and ConfigCheck (FlowSetupCheck) against this database's published flow.
 */
final class FlowEvalBuilder implements AiEvalBuilder
{
    public function __construct(private readonly FlowSetupCheck $check) {}

    public function build(array $example): AiCall
    {
        $description = $example['description'] ?? null;
        if (! is_string($description) || trim($description) === '') {
            throw new \InvalidArgumentException('description missing');
        }
        $base = is_string($example['base_template'] ?? null) ? $example['base_template'] : null;
        $answers = array_values(array_filter((array) ($example['answers'] ?? []), fn ($a) => is_array($a) && is_string($a['question'] ?? null) && is_string($a['answer'] ?? null)));

        return FlowDraftProposer::call($description, $base, $answers, Flow::current()?->publishedVersion?->definition['stages'] ?? null);
    }

    public function problems(array $example, ?array $output): array
    {
        if (($example['expect']['flow_check'] ?? false) !== true || $output === null) {
            return [];
        }
        $checks = $this->check->run(FlowSetupDraft::fromOutput($output));
        if ($checks['passes']) {
            return [];
        }

        $codes = [...array_column($checks['flow_issues'], 'code'), ...array_keys($checks['config_errors'])];

        return ['flow/config check: '.implode(', ', array_slice(array_values(array_unique($codes)), 0, 5))];
    }
}
