<?php

namespace App\Http\Requests\Returns;

/**
 * Inspection correction (M-15-T04): the part, its corrected good quantity and the reason.
 */
class CorrectReturnRequest extends ReturnWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'part_id' => ['required', 'integer'],
            'good' => self::quantityRule(true),
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function goodMilli(): int
    {
        return self::milli($this->validated('good'));
    }
}
