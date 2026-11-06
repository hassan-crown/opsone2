<?php

namespace App\Http\Requests\Account;

use App\Access\Access;
use App\Access\Permission;
use App\Finance\BankTransfer\SaudiIban;
use App\Models\FacilityBankAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P02);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'iban' => SaudiIban::normalize((string) $this->input('iban', '')),
            'swift_code' => strtoupper(trim((string) $this->input('swift_code', ''))),
            'is_default' => $this->boolean('is_default'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'bank_name' => ['required', 'string', 'max:100'],
            'beneficiary_name' => ['required', 'string', 'max:150'],
            'iban' => ['required', 'string', new SaudiIban, Rule::unique('facility_bank_accounts', 'iban')
                ->ignore($account instanceof FacilityBankAccount ? $account->id : null)],
            'account_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z\-\s]+$/'],
            'swift_code' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'currency' => ['required', 'string', Rule::in(config('account.currencies'))],
            'transfer_note' => ['nullable', 'string', 'max:300'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'iban.unique' => __('bank.validation.iban_taken'),
            'swift_code.regex' => __('bank.validation.swift'),
        ];
    }
}
