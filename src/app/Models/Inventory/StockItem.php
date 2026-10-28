<?php

namespace App\Models\Inventory;

use App\Inventory\StockUnit;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogReadyVariant;
use Illuminate\Database\Eloquent\Model;

/**
 * A tracked item: a ready variant (always `piece`) or a quantity-tracked material, with its one stock unit (Q-43 a)
 * and an optional reorder level. Catalog rows stay the reference data; stock never lives on them.
 *
 * @property int $id
 * @property string $item_type
 * @property int $item_id
 * @property StockUnit $unit
 * @property int|null $reorder_level_milli
 * @property int $lock_version
 */
class StockItem extends Model
{
    public const READY_VARIANT = 'ready_variant';

    public const MATERIAL = 'material';

    public const TYPES = [self::READY_VARIANT, self::MATERIAL];

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    /** Set by StockItems::preload() for lists; false = not preloaded. */
    private CatalogReadyVariant|CatalogMaterial|false|null $preloadedCatalog = false;

    public function catalogItem(): CatalogReadyVariant|CatalogMaterial|null
    {
        if ($this->preloadedCatalog !== false) {
            return $this->preloadedCatalog;
        }

        return $this->item_type === self::READY_VARIANT
            ? CatalogReadyVariant::query()->with('product')->find($this->item_id)
            : CatalogMaterial::query()->find($this->item_id);
    }

    public function preloadCatalog(CatalogReadyVariant|CatalogMaterial|null $catalog): void
    {
        $this->preloadedCatalog = $catalog;
    }

    protected function casts(): array
    {
        return [
            'unit' => StockUnit::class,
            'reorder_level_milli' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
