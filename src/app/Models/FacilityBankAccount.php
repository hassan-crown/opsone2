<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of the facility's bank accounts for customer transfers (ADR-0046). Only the active default one is shown to
 * customers, through `BankTransferDetails`.
 *
 * @property int $id
 * @property string $bank_name
 * @property string $beneficiary_name
 * @property string $iban normalised: uppercase, no spaces
 * @property string|null $account_number
 * @property string|null $swift_code
 * @property string $currency
 * @property string|null $transfer_note
 * @property bool $is_default
 * @property bool $is_active
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['bank_name', 'beneficiary_name', 'iban', 'account_number', 'swift_code', 'currency', 'transfer_note', 'is_default', 'is_active'])]
class FacilityBankAccount extends Model
{
    protected $attributes = ['currency' => 'SAR', 'is_default' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
