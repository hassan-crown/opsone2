<?php

namespace App\Models\Catalog;

use App\Access\Permission;
use App\Catalog\CatalogStatus;
use App\Catalog\PricingBasis;
use App\Files\Contracts\AttachmentOwner;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A made-to-order product definition (COM-04, P-11). `reference_price_minor` null means "price unknown", never 0.
 *
 * @property int $id
 * @property string|null $category
 * @property Translated $name
 * @property Translated $description
 * @property PricingBasis $pricing_basis
 * @property int|null $reference_price_minor
 * @property list<array<string, mixed>> $option_groups
 * @property CarbonInterface|null $disabled_at
 */
class CatalogProduct extends Model implements AttachmentOwner, CatalogItem
{
    use CatalogRecord;

    protected $guarded = ['id', 'current_version'];

    protected $attributes = ['current_version' => 1, 'status' => 'active'];

    public function versionPayload(): array
    {
        return [
            'category' => $this->category,
            'name' => $this->name->toArray(),
            'description' => $this->description->toArray(),
            'pricing_basis' => $this->pricing_basis->value,
            'reference_price_minor' => $this->reference_price_minor,
            'option_groups' => $this->option_groups,
        ];
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P11;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P11;
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'description' => TranslatableString::class,
            'pricing_basis' => PricingBasis::class,
            'reference_price_minor' => 'integer',
            'option_groups' => 'array',
            'status' => CatalogStatus::class,
            'disabled_at' => 'datetime',
        ];
    }
}
