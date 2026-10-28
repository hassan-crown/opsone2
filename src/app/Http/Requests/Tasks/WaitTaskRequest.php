<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M-07-T03: what the work waits for and when to follow up. Authorization happens on the task inside the Action (central Gate).
 */
class WaitTaskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'need' => ['required', 'string', 'max:1000'],
            'follow_up_on' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    public function lockVersion(): ?int
    {
        return $this->filled('lock_version') ? (int) $this->input('lock_version') : null;
    }
}
