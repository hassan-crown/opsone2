<?php

namespace App\Finance\BankTransfer;

use App\Models\FacilityBankAccount;

/**
 * What a customer is told about paying by bank transfer (ADR-0046): the facility's active default account only, as an
 * allow-list (never the other accounts, ids, status or who edited it). Null when the facility has no active account —
 * then nothing is shown to the customer and the owner is reminded in the settings and on the home page.
 */
final class BankTransferDetails
{
    public static function account(): ?FacilityBankAccount
    {
        return FacilityBankAccount::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    public static function missing(): bool
    {
        return ! FacilityBankAccount::query()->where('is_active', true)->exists();
    }

    /**
     * @return array{bank_name: string, beneficiary_name: string, iban: string, iban_grouped: string, account_number: string|null, swift_code: string|null, currency: string, transfer_note: string|null}|null
     */
    public static function forCustomer(): ?array
    {
        $account = self::account();
        if ($account === null) {
            return null;
        }

        return [
            'bank_name' => $account->bank_name,
            'beneficiary_name' => $account->beneficiary_name,
            'iban' => $account->iban,
            'iban_grouped' => SaudiIban::grouped($account->iban),
            'account_number' => $account->account_number,
            'swift_code' => $account->swift_code,
            'currency' => $account->currency,
            'transfer_note' => $account->transfer_note,
        ];
    }

    /**
     * The sentence appended to a due reminder (SMS/mail variable `:bank`), with a leading space; empty without an account.
     */
    public static function messageLine(string $locale, string $reference): string
    {
        $account = self::account();
        if ($account === null) {
            return '';
        }

        return ' '.__('bank.message_line', [
            'bank' => $account->bank_name,
            'beneficiary' => $account->beneficiary_name,
            'iban' => SaudiIban::grouped($account->iban),
            'reference' => $reference,
        ], $locale);
    }
}
