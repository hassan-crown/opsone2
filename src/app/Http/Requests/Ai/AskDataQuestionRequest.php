<?php

namespace App\Http\Requests\Ai;

use App\Ai\Insights\DataQuestion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A question to «اسأل بياناتك». Any signed-in user may ask; what can be answered is decided by DataQuestion from the
 * reports and branches that user may open (never by this request).
 */
class AskDataQuestionRequest extends FormRequest
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
        return ['question' => ['required', 'string', 'min:3', 'max:'.DataQuestion::MAX_QUESTION]];
    }
}
