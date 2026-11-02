<?php

namespace App\Http\Requests\Rules;

use App\Finance\Plans\PaymentPlans;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A payment plan as the editor sends it (ADR-0037). Authorization is the action's (P-104).
 */
class PaymentPlanRequest extends FormRequest
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
        return [...PaymentPlans::rules(), 'lock_version' => ['nullable', 'integer']];
    }
}
