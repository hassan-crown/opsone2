<?php

namespace App\Workflow;

use Illuminate\Validation\ValidationException;

/**
 * Outcome of FlowCheck: structured issues (`code`, `path`, `stage`, `action`, translated `message`) and, when there
 * are none, the normalized definition that is stored.
 */
final readonly class FlowCheckResult
{
    /**
     * @param  list<array{code: string, path: string, stage: string|null, action: string|null, message: string}>  $issues
     * @param  list<array<string, mixed>>  $stages
     */
    public function __construct(public array $issues, public array $stages) {}

    public function passes(): bool
    {
        return $this->issues === [];
    }

    /**
     * @return array{stages: list<array<string, mixed>>}
     */
    public function definition(): array
    {
        return ['stages' => $this->stages];
    }

    /**
     * Refuses with a 422 whose errors are keyed by the issue path (`stages.2.actions.0.type`) and whose
     * `issues` carry the full structure for the FlowCheck screen.
     */
    public function throwIfFails(string $prefix = ''): void
    {
        if ($this->passes()) {
            return;
        }

        $messages = [];
        foreach ($this->issues as $issue) {
            $messages[$prefix.$issue['path']][] = $issue['message'];
        }

        throw new FlowCheckFailed($this, ValidationException::withMessages($messages)->validator);
    }
}
