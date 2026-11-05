<?php

namespace App\Http\Requests\FieldSales;

use App\Actions\Workflow\RecordMeasurement;
use Illuminate\Foundation\Http\FormRequest;

/** A measurement recorded on site (COM-07 rules, RecordMeasurement validates again). */
class SalesMeasurementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...RecordMeasurement::rules(),
        ];
    }
}
