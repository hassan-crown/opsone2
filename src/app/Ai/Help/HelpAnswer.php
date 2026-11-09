<?php

namespace App\Ai\Help;

/**
 * What the help center shows: an answer with its sources, or the reason there is none.
 * `answered`: answer + sources. `no_answer`: nothing in the guide supports one (offer support).
 * `unavailable`: the model could not be reached or credits ran out. `off`: assistant not offered (switch or license).
 */
final readonly class HelpAnswer
{
    public const ANSWERED = 'answered';

    public const NO_ANSWER = 'no_answer';

    public const UNAVAILABLE = 'unavailable';

    public const OFF = 'off';

    /**
     * @param  list<array{slug: string, title: string, url: string}>  $sources
     */
    public function __construct(
        public string $status,
        public ?string $answer = null,
        public array $sources = [],
    ) {}

    /**
     * @return array{status: string, answer: string|null, sources: list<array{slug: string, title: string, url: string}>}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'answer' => $this->answer, 'sources' => $this->sources];
    }
}
