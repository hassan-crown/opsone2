<?php

namespace App\Http\Requests\Field;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «وصلت»: the location is optional — sent only when the technician's device allowed reading it once.
 */
class ArriveRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
