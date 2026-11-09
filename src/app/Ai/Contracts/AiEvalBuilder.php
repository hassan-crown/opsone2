<?php

namespace App\Ai\Contracts;

use App\Ai\AiCall;

/**
 * Builds an evaluation example's call from a short fixture line (`"builder": "<name>"`, registered in
 * `ai.eval_builders`), so the set runs the feature's real prompt, schema and facts instead of a copy that drifts.
 */
interface AiEvalBuilder
{
    /**
     * @param  array<string, mixed>  $example  the fixture line
     */
    public function build(array $example): AiCall;

    /**
     * Feature-specific checks of a successful answer, beyond `equals` / `not_contains` (empty = pass).
     *
     * @param  array<string, mixed>  $example
     * @param  array<string, mixed>|null  $output
     * @return list<string>
     */
    public function problems(array $example, ?array $output): array;
}
