<?php

namespace App\Ai;

/**
 * A driver's answer. `text` is the raw JSON the model produced; the gateway decodes and validates it.
 */
final readonly class AiResponse
{
    public const STOP_END = 'end_turn';

    public const STOP_MAX_TOKENS = 'max_tokens';

    public const STOP_REFUSAL = 'refusal';

    /** The `log` driver's marker: nothing was sent. */
    public const STOP_NOT_SENT = 'not_sent';

    public function __construct(
        public string $text,
        public string $stopReason,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    public static function notSent(string $model): self
    {
        return new self('', self::STOP_NOT_SENT, $model);
    }

    public function wasSent(): bool
    {
        return $this->stopReason !== self::STOP_NOT_SENT;
    }
}
