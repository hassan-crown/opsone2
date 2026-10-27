<?php

namespace App\Models\Catalog;

use App\Access\Permission;
use App\Catalog\Availability;
use App\Catalog\CatalogStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A material or model reusable across made-to-order products (COM-05, P-13). Descriptive by default; quantity
 * tracking only flags the item for the stock domain (P7) and never changes a balance here.
 *
 * @property int $id
 * @property string|null $material_group
 * @property Translated $name
 * @property Translated $description
 * @property string $unit
 * @property Availability $availability
 * @property string|null $supplier_name
 * @property bool $track_quantity
 * @property CarbonInterface|null $disabled_at
 */
class CatalogMaterial extends Model implements AttachmentOwner, CatalogItem
{
    use CatalogRecord;

    protected $guarded = ['id', 'current_version'];

    protected $attributes = ['current_version' => 1, 'status' => 'active'];

    public function versionPayload(): array
    {
        // Supplier and tracking are internal notes: they stay out of what an order line freezes.
        return [
            'material_group' => $this->material_group,
            'name' => $this->name->toArray(),
            'description' => $this->description->toArray(),
            'unit' => $this->unit,
            'availability' => $this->availability->value,
        ];
    }

    public function auditSnapshot(): array
    {
        return [...$this->versionPayload(), 'supplier_name' => $this->supplier_name, 'track_quantity' => $this->track_quantity];
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P13;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P13;
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'description' => TranslatableString::class,
            'availability' => Availability::class,
            'track_quantity' => 'boolean',
            'status' => CatalogStatus::class,
            'disabled_at' => 'datetime',
        ];
    }
}
