<?php

namespace App\Http\Requests\FieldSales;

use App\Actions\FieldSales\UpdateFieldSalesSettings;
use Illuminate\Foundation\Http\FormRequest;

/** Settings › field sales (P-104, ADR-0041). */
class FieldSalesSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...UpdateFieldSalesSettings::rules(),
        ];
    }
}
