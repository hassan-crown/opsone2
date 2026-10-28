<?php

namespace App\Http\Requests\Site;

use App\Access\Authorizer;
use App\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(Authorizer::class)->can($this->user(), Permission::P56);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['string', Rule::in(['ar', 'en'])],
            // true = «publish the complete languages now», the rest keep their last published copy.
            'only_complete' => ['sometimes', 'boolean'],
        ];
    }
}
