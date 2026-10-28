<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FinReminder: the dues selected in the work list. Access is re-checked per due by the reminder service.
 */
class ReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['due_ids' => ['required', 'array', 'min:1', 'max:200'], 'due_ids.*' => ['integer', 'distinct']];
    }

    /**
     * @return list<int>
     */
    public function dueIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->validated('due_ids')));
    }
}
