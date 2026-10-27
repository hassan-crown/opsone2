<?php

namespace App\Models\Catalog;

use App\Access\Permission;
use App\Catalog\CatalogStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ready-goods product (COM-06, P-12; license capability `ready_goods`). Sold through its variants.
 *
 * @property int $id
 * @property Translated $name
 * @property Translated $description
 * @property CarbonInterface|null $disabled_at
 */
class CatalogReadyProduct extends Model implements AttachmentOwner, CatalogItem
{
    use CatalogRecord;

    protected $guarded = ['id', 'current_version'];

    protected $attributes = ['current_version' => 1, 'status' => 'draft'];

    /**
     * @return HasMany<CatalogReadyVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(CatalogReadyVariant::class, 'ready_product_id')->orderBy('id');
    }

    public function versionPayload(): array
    {
        return [
            'name' => $this->name->toArray(),
            'description' => $this->description->toArray(),
        ];
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P12;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P12;
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'description' => TranslatableString::class,
            'status' => CatalogStatus::class,
            'disabled_at' => 'datetime',
        ];
    }
}
