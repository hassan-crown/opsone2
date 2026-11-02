<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\Agreements\AgreementWriteRequest;

/**
 * Email a purchase order to its supplier (P-46, ADR-0038). Every field may be left empty to use the supplier's email
 * and the facility's texts. The key keeps a double click from sending twice.
 */
class EmailPurchaseRequest extends AgreementWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return [
            'recipient' => ['nullable', 'email', 'max:190'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
            'locale' => ['nullable', 'in:ar,en'],
        ];
    }
}
