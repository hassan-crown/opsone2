<?php

namespace App\Ai\Insights;

/**
 * What «اسأل بياناتك» returns. `answered`: answer + the report runs it rests on. `no_answer`: the question is not
 * answerable from the reports this user may open, or the model's output failed a check (never shown).
 * `unavailable`: the model could not be reached or credits ran out. `off`: not offered (switch, license, no report).
 */
final readonly class DataAnswer
{
    public const ANSWERED = 'answered';

    public const NO_ANSWER = 'no_answer';

    public const UNAVAILABLE = 'unavailable';

    public const OFF = 'off';

    /**
     * @param  list<array{tool: string, from: string, to: string, branch_id: int|null}>  $sources
     */
    public function __construct(
        public string $status,
        public ?string $answer = null,
        public array $sources = [],
    ) {}

    /**
     * @return array{status: string, answer: string|null, sources: list<array{tool: string, from: string, to: string, branch_id: int|null}>}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'answer' => $this->answer, 'sources' => $this->sources];
    }
}
