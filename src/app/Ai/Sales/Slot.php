<?php

namespace App\Ai\Sales;

use Carbon\CarbonImmutable;

/**
 * One bookable measurement slot computed by SlotFinder: a start/end (UTC) and the staff member who is free for it.
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $memberId,
        public string $label,
    ) {}

    /**
     * @return array{starts_at: string, ends_at: string, member_id: int, label: string}
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'member_id' => $this->memberId,
            'label' => $this->label,
        ];
    }
}
