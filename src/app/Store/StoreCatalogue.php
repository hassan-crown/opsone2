<?php

namespace App\Store;

use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Order;
use App\Models\Store\StoreSettings;
use Illuminate\Support\Collection;

/**
 * Read side of the store (WEB-05): published ready products with their selectable variants, current prices and the
 * whole pieces available at the store's stock location — the first usable location of the receiving branch, the same
 * one the checkout reserves from (OrderStock::usableFor). Availability shown is a hint; the reservation at checkout
 * decides under the balance lock.
 */
final class StoreCatalogue
{
    public function location(): ?StockLocation
    {
        $branchId = StoreSettings::current()->branch_id;

        return $branchId === null ? null : OrderStock::locationsFor(new Order(['branch_id' => $branchId]))->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function products(string $locale, ?int $limit = null): array
    {
        $query = CatalogReadyProduct::query()->selectable()
            ->whereHas('variants', fn ($q) => $q->selectable())
            ->with(['variants' => fn ($q) => $q->selectable()->orderByDesc('is_default')->orderBy('id')])
            ->orderBy('id');
        if ($limit !== null) {
            $query->limit($limit);
        }
        $products = $query->get();
        $available = $this->availability(array_values(array_map('intval', $products->flatMap(fn (CatalogReadyProduct $p) => $p->variants->pluck('id'))->all())));

        return array_values($products->map(fn (CatalogReadyProduct $p) => $this->product($p, $locale, $available))->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $productId, string $locale): ?array
    {
        $product = CatalogReadyProduct::query()->whereKey($productId)->selectable()
            ->with(['variants' => fn ($q) => $q->selectable()->orderByDesc('is_default')->orderBy('id')])
            ->first();
        if ($product === null || $product->variants->isEmpty()) {
            return null;
        }

        return $this->product($product, $locale, $this->availability(array_values(array_map('intval', $product->variants->pluck('id')->all()))));
    }

    public function variant(int $variantId): ?CatalogReadyVariant
    {
        $variant = CatalogReadyVariant::query()->with('product')->find($variantId);

        return $variant !== null && $variant->isSelectable() && $variant->product->isSelectable() ? $variant : null;
    }

    /**
     * Whole pieces available now at the store location, per variant id.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function availability(array $variantIds): array
    {
        $location = $this->location();
        $out = array_fill_keys($variantIds, 0);
        if ($location === null || $variantIds === []) {
            return $out;
        }
        $items = StockItem::query()->where('item_type', StockItem::READY_VARIANT)->whereIn('item_id', $variantIds)->pluck('item_id', 'id');
        $balances = StockBalance::query()->where('location_id', $location->id)->whereIn('stock_item_id', $items->keys())->get();
        foreach ($balances as $balance) {
            $variantId = (int) $items[$balance->stock_item_id];
            $out[$variantId] = max(0, intdiv($balance->availableMilli(), Quantity::SCALE));
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $available
     * @return array<string, mixed>
     */
    private function product(CatalogReadyProduct $p, string $locale, array $available): array
    {
        /** @var Collection<int, CatalogReadyVariant> $variants */
        $variants = $p->variants;
        $rows = $variants->map(fn (CatalogReadyVariant $v) => [
            'id' => $v->id,
            'code' => $v->code,
            'name' => (string) $v->name->get($locale),
            'price_minor' => $v->price_minor,
            'available' => $available[$v->id] ?? 0,
        ])->values()->all();

        return [
            'id' => $p->id,
            'name' => (string) $p->name->get($locale),
            'description' => (string) ($p->description->get($locale) ?? ''),
            'from_minor' => (int) $variants->min('price_minor'),
            'available' => array_sum(array_column($rows, 'available')),
            'variants' => $rows,
        ];
    }
}
