<?php

namespace App\Http\Requests\Returns;

/**
 * ReturnInspect: per received part the good / damaged / rejected quantities (their sum is the part's quantity).
 */
class InspectReturnRequest extends ReturnWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
            'results' => ['required', 'array', 'min:1', 'max:200'],
            'results.*.part_id' => ['required', 'integer'],
            'results.*.good' => self::quantityRule(),
            'results.*.damaged' => self::quantityRule(),
            'results.*.rejected' => self::quantityRule(),
        ];
    }

    /**
     * @return list<array{part_id: int, good_milli: int, damaged_milli: int, rejected_milli: int}>
     */
    public function results(): array
    {
        return array_map(fn (array $r) => [
            'part_id' => (int) $r['part_id'],
            'good_milli' => self::milli($r['good'] ?? null),
            'damaged_milli' => self::milli($r['damaged'] ?? null),
            'rejected_milli' => self::milli($r['rejected'] ?? null),
        ], array_values((array) $this->validated('results')));
    }

    public function noteText(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
