<?php

namespace App\Http\Requests\EInvoicing;

use App\Catalog\MinorUnits;
use App\EInvoicing\ZatcaAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual credit or debit note against an issued invoice (owner): the VAT-inclusive amount and the reason ZATCA
 * requires on every note (KSA-10).
 */
class IssueNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ZatcaAccess::manages($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['credit_note', 'debit_note'])],
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function amountMinor(): int
    {
        return MinorUnits::fromInput((string) $this->validated('amount'));
    }
}
