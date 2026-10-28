<?php

namespace App\Http\Requests\Site;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Site\SiteImage;
use Illuminate\Foundation\Http\FormRequest;

class ImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(Authorizer::class)->can($this->user(), $this->route('kind') === null ? Permission::P55 : Permission::P100);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['file' => ['required', new SiteImage(square: $this->route('kind') === 'favicon')]];
    }
}
