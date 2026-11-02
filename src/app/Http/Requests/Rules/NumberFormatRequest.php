<?php

namespace App\Http\Requests\Rules;

use App\Actions\Rules\SaveNumberFormat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One sequence number format (ADR-0037). Authorization is the action's (P-103).
 */
class NumberFormatRequest extends FormRequest
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
        return SaveNumberFormat::rules();
    }
}
