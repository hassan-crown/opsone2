<?php

namespace App\Http\Requests\Site;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Models\Site\SitePage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Page create / draft save. Authorization is the writer's (P-55); this checks the shape. The slug is a lowercase
 * ASCII path segment that never equals a reserved or existing page path.
 */
class PageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(Authorizer::class)->can($this->user(), Permission::P55);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var SitePage|null $page */
        $page = $this->route('page');

        return [
            'slug' => [$page === null ? 'required' : 'sometimes', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::notIn(SitePage::RESERVED_SLUGS), Rule::unique('site_pages', 'slug')->ignore($page?->id)],
            'show_in_nav' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'array'],
            'title.ar' => ['nullable', 'string', 'max:160'],
            'title.en' => ['nullable', 'string', 'max:160'],
            'content' => ['sometimes', 'array'],
            'content.*.title' => ['nullable', 'string', 'max:160'],
            'content.*.meta_description' => ['nullable', 'string', 'max:320'],
            'content.*.sections' => ['nullable', 'array', 'max:30'],
        ];
    }
}
