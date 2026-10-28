<?php

namespace App\Http\Requests\Tasks;

use App\Actions\Workflow\RecordMeasurement;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Measurement entry by the executor (COM-07); the rules are the engine's. Authorization happens on the task inside the Action (central Gate).
 */
class TaskMeasurementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...RecordMeasurement::rules(),
        ];
    }

    public function lockVersion(): ?int
    {
        return $this->filled('lock_version') ? (int) $this->input('lock_version') : null;
    }
}
