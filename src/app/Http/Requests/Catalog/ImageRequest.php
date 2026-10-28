<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Product images. Authorization happens in AttachmentService against the owning record; the stored file is
 * type-checked there by content, this only narrows the choice to pictures.
 */
class ImageRequest extends FormRequest
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
        return ['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']];
    }
}
