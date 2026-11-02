<?php

namespace App\Http\Requests\Rules;

use App\Actions\Rules\SaveDocumentClause;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A library clause (ADR-0037). Authorization is the action's (P-104).
 */
class DocumentClauseRequest extends FormRequest
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
        return [...SaveDocumentClause::rules(), 'lock_version' => ['nullable', 'integer']];
    }
}
