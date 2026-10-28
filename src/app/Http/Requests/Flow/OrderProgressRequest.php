<?php

namespace App\Http\Requests\Flow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Writes of the order progress screens (M-04, M-06, M-27). `lock_version` is the order version the screen read, so a
 * change made meanwhile gets a 409. Authorization on the record is the Actions' own.
 */
class OrderProgressRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lock = ['lock_version' => ['required', 'integer', 'min:1']];
        $reason = ['reason' => ['required', 'string', 'max:1000']];

        $method = $this->route()->getActionMethod();

        return match ($method) {
            'pause' => [...$lock, ...$reason, 'follow_up_user_id' => ['required', 'integer'], 'review_on' => ['nullable', 'date', 'after_or_equal:today']],
            'resume' => [...$lock, 'note' => ['nullable', 'string', 'max:1000']],
            'allow', 'rework', 'editUpcoming' => [...$lock, ...$reason, ...($method === 'editUpcoming' ? ['stages' => ['required', 'array', 'max:30']] : [])],
            default => $lock,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stages(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values((array) $this->input('stages', []));
    }
}
