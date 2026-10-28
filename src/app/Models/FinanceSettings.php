<?php

namespace App\Models;

use App\Money\VatCalculator;
use Illuminate\Database\Eloquent\Model;

/**
 * Facility finance settings (Q-35 a, SET-03): VAT rate in basis points, whether entered prices include VAT, the
 * default validity of a sent quote and whether the facility uses contracts at all (AGR-03: optional). One row;
 * current() falls back to the defaults before it is saved. Changing it never re-prices a sent document.
 *
 * @property int $vat_rate_bp
 * @property bool $prices_include_vat
 * @property int $quote_validity_days
 * @property bool $contracts_enabled
 * @property bool $cash_proof_required owner-only (ADR-0014): a cash collection needs a proof file
 * @property int $lock_version
 * @property int|null $updated_by
 */
class FinanceSettings extends Model
{
    /** Saudi VAT 15%, prices entered VAT-inclusive, quotes valid 14 days, contracts available. */
    public const DEFAULTS = [
        'vat_rate_bp' => 1500,
        'prices_include_vat' => true,
        'quote_validity_days' => 14,
        'contracts_enabled' => true,
    ];

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'cash_proof_required' => true];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(self::DEFAULTS);
    }

    /**
     * Whether a cash collection must carry a proof file (default yes). Other methods always need one.
     */
    public function cashProofRequired(): bool
    {
        return $this->cash_proof_required ?? true;
    }

    public function vat(): VatCalculator
    {
        return new VatCalculator($this->vat_rate_bp, $this->prices_include_vat);
    }

    protected function casts(): array
    {
        return [
            'vat_rate_bp' => 'integer',
            'prices_include_vat' => 'boolean',
            'quote_validity_days' => 'integer',
            'contracts_enabled' => 'boolean',
            'cash_proof_required' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
