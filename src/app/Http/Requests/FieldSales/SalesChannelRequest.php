<?php

namespace App\Http\Requests\FieldSales;

use App\Actions\FieldSales\ChangeSalesChannel;
use Illuminate\Foundation\Http\FormRequest;

/** Correcting an order's sales channel and field seller (P-106, ADR-0041). */
class SalesChannelRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...ChangeSalesChannel::rules(),
        ];
    }
}
