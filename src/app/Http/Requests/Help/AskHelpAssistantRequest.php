<?php

namespace App\Http\Requests\Help;

use App\Ai\Help\HelpAssistant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A question to the help assistant. The help center is open to every signed-in staff member; what may be answered
 * is decided by HelpReader inside HelpAssistant.
 */
class AskHelpAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['question' => ['required', 'string', 'min:3', 'max:'.HelpAssistant::MAX_QUESTION]];
    }
}
