<?php

namespace App\Http\Requests\Rules;

use App\Actions\Rules\SaveMessageTemplate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One message wording (ADR-0037). Authorization is the action's (P-104).
 */
class MessageTemplateRequest extends FormRequest
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
        return SaveMessageTemplate::rules();
    }
}
