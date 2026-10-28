<?php

namespace App\Models\Procurement;

use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogReadyVariant;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of a purchase order, quantities in thousandths of the item's fixed unit (Q-43). `accepted_milli` is what
 * satisfies the order; `rejected_milli` and the damaged part stay for follow-up with the supplier and never reduce
 * the remainder.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $line_no
 * @property string $item_type
 * @property int $item_id
 * @property Translated $item_name
 * @property string $unit
 * @property int $quantity_milli
 * @property int|null $unit_cost_minor
 * @property int $net_minor
 * @property int $vat_minor
 * @property int $gross_minor
 * @property int|null $order_id
 * @property int $received_milli
 * @property int $accepted_milli
 * @property int $rejected_milli
 * @property-read PurchaseOrder $purchaseOrder
 */
class PurchaseOrderLine extends Model
{
    public const MATERIAL = 'material';

    public const READY_VARIANT = 'ready_variant';

    protected $guarded = ['id'];

    protected $attributes = ['received_milli' => 0, 'accepted_milli' => 0, 'rejected_milli' => 0, 'net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0];

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Still to be accepted; zero once satisfied. Never negative. */
    public function remainingMilli(): int
    {
        return max(0, $this->quantity_milli - $this->accepted_milli);
    }

    /**
     * Whether the stock domain keeps a balance for the item: every ready variant, and only the made-to-order materials
     * the facility chose to track (FUL-04). Untracked materials are descriptive and never reach the stock.
     */
    public function tracksStock(): bool
    {
        if ($this->item_type === self::READY_VARIANT) {
            return true;
        }

        return (bool) CatalogMaterial::query()->whereKey($this->item_id)->value('track_quantity');
    }

    /**
     * @return array{type: string, id: int}
     */
    public function itemRef(): array
    {
        return ['type' => $this->item_type, 'id' => $this->item_id];
    }

    public static function itemExists(string $type, int $id): bool
    {
        return match ($type) {
            self::MATERIAL => CatalogMaterial::query()->whereKey($id)->exists(),
            self::READY_VARIANT => CatalogReadyVariant::query()->whereKey($id)->exists(),
            default => false,
        };
    }

    protected function casts(): array
    {
        return [
            'item_name' => TranslatableString::class,
            'quantity_milli' => 'integer',
            'unit_cost_minor' => 'integer',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'gross_minor' => 'integer',
            'received_milli' => 'integer',
            'accepted_milli' => 'integer',
            'rejected_milli' => 'integer',
        ];
    }
}
