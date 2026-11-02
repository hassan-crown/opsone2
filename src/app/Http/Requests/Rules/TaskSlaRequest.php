<?php

namespace App\Http\Requests\Rules;

use App\Actions\Rules\UpdateTaskSla;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Task SLA settings (ADR-0037). Authorization is the action's (P-103).
 */
class TaskSlaRequest extends FormRequest
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
        return UpdateTaskSla::rules();
    }
}
