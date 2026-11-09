<?php

namespace App\Ai;

/**
 * What the feature gets back. `output` is set only when `outcome` is ok, with the hidden identifiers restored.
 */
final readonly class AiResult
{
    /**
     * @param  array<string, mixed>|null  $output
     * @param  list<string>  $problems  validator findings (no customer text), for logs and tests
     */
    public function __construct(
        public AiOutcome $outcome,
        public ?array $output = null,
        public ?int $usageId = null,
        public array $problems = [],
    ) {}

    public function ok(): bool
    {
        return $this->outcome->isOk();
    }
}
