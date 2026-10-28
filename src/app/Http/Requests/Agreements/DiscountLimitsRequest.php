<?php

namespace App\Http\Requests\Agreements;

use App\Actions\Settings\UpdateDiscountLimits;
use Illuminate\Foundation\Http\FormRequest;

class DiscountLimitsRequest extends FormRequest
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
        return UpdateDiscountLimits::rules();
    }
}
