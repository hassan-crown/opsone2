<?php

namespace App\Ai;

/**
 * What a driver receives: already redacted, already assembled. Drivers never see the original identifiers.
 */
final readonly class AiRequest
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public AiTask $task,
        public string $model,
        public string $effort,
        public int $maxTokens,
        public int $timeoutSeconds,
        public string $system,
        public string $content,
        public array $schema,
    ) {}
}
