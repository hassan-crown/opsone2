<?php

namespace App\Models\EInvoicing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The seller's tax profile for ZATCA e-invoicing (ADR-0033): legal name, VAT number, commercial registration, national
 * address, the CSR's branch and industry, the environment and whether invoices are issued at all. One row; current()
 * returns an unsaved default before the owner saves it.
 *
 * @property int $id
 * @property bool $enabled
 * @property CarbonInterface|null $enabled_at
 * @property string $environment
 * @property string|null $seller_name_ar
 * @property string|null $seller_name_en
 * @property string|null $vat_number
 * @property string|null $commercial_registration
 * @property string|null $street
 * @property string|null $building_number
 * @property string|null $additional_number
 * @property string|null $district
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $branch_name
 * @property string|null $industry
 * @property int $lock_version
 * @property int|null $updated_by
 */
class ZatcaSettings extends Model
{
    public const PROFILE = [
        'seller_name_ar', 'seller_name_en', 'vat_number', 'commercial_registration', 'street', 'building_number',
        'additional_number', 'district', 'city', 'postal_code', 'branch_name', 'industry',
    ];

    /** Required before the first invoice: everything a ZATCA seller party needs. */
    public const REQUIRED = ['seller_name_ar', 'vat_number', 'commercial_registration', 'street', 'building_number', 'district', 'city', 'postal_code'];

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['enabled' => false, 'environment' => 'fake', 'lock_version' => 1];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }

    public function profileComplete(): bool
    {
        foreach (self::REQUIRED as $key) {
            if (trim((string) $this->{$key}) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The seller party as frozen into each invoice.
     *
     * @return array<string, string|null>
     */
    public function sellerSnapshot(): array
    {
        return [
            'name' => (string) $this->seller_name_ar,
            'name_en' => $this->seller_name_en,
            'vat_number' => (string) $this->vat_number,
            'commercial_registration' => (string) $this->commercial_registration,
            'street' => (string) $this->street,
            'building_number' => (string) $this->building_number,
            'additional_number' => $this->additional_number,
            'district' => (string) $this->district,
            'city' => (string) $this->city,
            'postal_code' => (string) $this->postal_code,
            'country' => 'SA',
        ];
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
