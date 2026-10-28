<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A write that carries only the optimistic-lock version (e.g. removing a line).
 */
class LockVersionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:1']];
    }
}
