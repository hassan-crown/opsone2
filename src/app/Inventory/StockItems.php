<?php

namespace App\Inventory;

use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Inventory\InventorySettings;
use App\Models\Inventory\StockItem;
use App\Support\Translatable\Translated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Registry of tracked items (Q-43 a). A ready variant is always counted in pieces; a material is tracked only when
 * its catalog row says so, in the one stock unit matched from its unit label (or set on the stock item screen).
 * Descriptive materials never get a stock row (FUL-04).
 */
final class StockItems
{
    public static function find(string $itemType, int $itemId): ?StockItem
    {
        return StockItem::query()->where('item_type', $itemType)->where('item_id', $itemId)->first();
    }

    /**
     * The item's stock row, created on first use. Refuses an unknown type, a missing or descriptive item, and a
     * material whose unit is not one of the enabled fixed units.
     */
    public static function resolve(string $itemType, int $itemId, string $field = 'item_id'): StockItem
    {
        if (! in_array($itemType, StockItem::TYPES, true)) {
            throw new InvalidArgumentException("Unknown stock item type {$itemType}.");
        }
        $existing = self::find($itemType, $itemId);
        if ($existing !== null) {
            return $existing;
        }

        if ($itemType === StockItem::READY_VARIANT) {
            if (! CatalogReadyVariant::query()->whereKey($itemId)->exists()) {
                throw ValidationException::withMessages([$field => __('stock.validation.item_missing')]);
            }
            $unit = StockUnit::Piece;
        } else {
            $material = CatalogMaterial::query()->find($itemId);
            if ($material === null) {
                throw ValidationException::withMessages([$field => __('stock.validation.item_missing')]);
            }
            if (! $material->track_quantity) {
                throw ValidationException::withMessages([$field => __('stock.validation.not_tracked')]);
            }
            $unit = StockUnit::fromLabel($material->unit);
            if ($unit === null || ! InventorySettings::current()->enables($unit)) {
                throw ValidationException::withMessages(['unit' => __('stock.validation.unit_unset')]);
            }
        }

        $now = now();
        // Concurrent first uses meet on the unique key; whoever loses reads the winner's row.
        DB::table('stock_items')->insertOrIgnore([
            'item_type' => $itemType, 'item_id' => $itemId, 'unit' => $unit->value,
            'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return self::find($itemType, $itemId) ?? throw new InvalidArgumentException('Stock item vanished.');
    }

    /**
     * The fixed unit code an item is (or would be) counted in, or null when it cannot be tracked yet.
     */
    public static function unitFor(string $itemType, int $itemId): ?string
    {
        $item = self::find($itemType, $itemId);
        if ($item !== null) {
            return $item->unit->value;
        }
        if ($itemType === StockItem::READY_VARIANT) {
            return StockUnit::Piece->value;
        }
        $material = CatalogMaterial::query()->find($itemId);

        return $material === null || ! $material->track_quantity ? null : StockUnit::fromLabel($material->unit)?->value;
    }

    /**
     * Loads the catalog rows of many stock items in two queries, so describe() over a list reads no row of its own.
     *
     * @param  iterable<StockItem|null>  $items
     */
    public static function preload(iterable $items): void
    {
        $byType = [StockItem::READY_VARIANT => [], StockItem::MATERIAL => []];
        foreach ($items as $item) {
            if ($item !== null) {
                $byType[$item->item_type][] = $item;
            }
        }
        $ids = static fn (array $list): array => array_values(array_unique(array_map(fn (StockItem $i) => $i->item_id, $list)));
        $variants = $byType[StockItem::READY_VARIANT] === [] ? collect()
            : CatalogReadyVariant::query()->with('product')->whereIn('id', $ids($byType[StockItem::READY_VARIANT]))->get()->keyBy('id');
        $materials = $byType[StockItem::MATERIAL] === [] ? collect()
            : CatalogMaterial::query()->whereIn('id', $ids($byType[StockItem::MATERIAL]))->get()->keyBy('id');
        foreach ($byType[StockItem::READY_VARIANT] as $item) {
            $item->preloadCatalog($variants->get($item->item_id));
        }
        foreach ($byType[StockItem::MATERIAL] as $item) {
            $item->preloadCatalog($materials->get($item->item_id));
        }
    }

    /**
     * Display facts of an item: bilingual name, code (variant) and unit.
     *
     * @return array{id: int, type: string, item_id: int, name: string, code: string|null, unit: string, reorder_level: string|null}
     */
    public static function describe(StockItem $item): array
    {
        $catalog = $item->catalogItem();
        $name = '#'.$item->item_id;
        $code = null;
        if ($catalog instanceof CatalogReadyVariant) {
            $name = self::text($catalog->product->name).' — '.self::text($catalog->name);
            $code = $catalog->code;
        } elseif ($catalog instanceof CatalogMaterial) {
            $name = self::text($catalog->name);
        }

        return [
            'id' => $item->id,
            'type' => $item->item_type,
            'item_id' => $item->item_id,
            'name' => $name,
            'code' => $code,
            'unit' => $item->unit->value,
            'reorder_level' => $item->reorder_level_milli === null ? null : Quantity::format($item->reorder_level_milli),
        ];
    }

    private static function text(Translated $text): string
    {
        return (string) $text->get(app()->getLocale());
    }
}
