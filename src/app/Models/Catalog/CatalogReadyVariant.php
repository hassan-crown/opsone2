<?php

namespace App\Models\Catalog;

use App\Catalog\CatalogStatus;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sellable variant of a ready product: its own unique code, price (tax included, minor units) and status.
 * Stock is owned by the inventory domain (P7), never stored here. A variant is selectable only while it is
 * published and its product is too.
 *
 * @property int $id
 * @property int $ready_product_id
 * @property string $code
 * @property Translated $name
 * @property int $price_minor
 * @property bool $is_default
 * @property CarbonInterface|null $disabled_at
 * @property-read CatalogReadyProduct $product
 */
class CatalogReadyVariant extends Model implements CatalogItem
{
    use CatalogRecord;

    protected $guarded = ['id', 'current_version'];

    protected $attributes = ['current_version' => 1, 'status' => 'draft'];

    /**
     * @return BelongsTo<CatalogReadyProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogReadyProduct::class, 'ready_product_id');
    }

    public function isSelectable(): bool
    {
        return $this->status->isSelectable() && $this->product->isSelectable();
    }

    public function versionPayload(): array
    {
        return [
            'ready_product_id' => $this->ready_product_id,
            'product_name' => $this->product->name->toArray(),
            'code' => $this->code,
            'name' => $this->name->toArray(),
            'price_minor' => $this->price_minor,
            'is_default' => $this->is_default,
        ];
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'price_minor' => 'integer',
            'is_default' => 'boolean',
            'status' => CatalogStatus::class,
            'disabled_at' => 'datetime',
        ];
    }
}
