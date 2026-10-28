<?php

namespace App\Procurement;

use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Order;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Support\Translatable\Translated;
use Illuminate\Validation\ValidationException;

/**
 * Writes the lines of a draft purchase order from already-parsed input: each line freezes the item's name and fixed
 * unit (Q-43), carries a quantity in thousandths and an optional reference cost. A writer without P-82 can neither set
 * nor see costs: a line they resend keeps the cost it had, a new one has none.
 */
final class PurchaseLines
{
    /** Unit of a ready variant: a piece (ready goods are counted, never measured). */
    public const PIECE = 'piece';

    /**
     * @param  list<array<string, mixed>>  $lines  {id?, item_type, item_id, quantity_milli, unit_cost_minor?, order_id?}
     */
    public function replace(PurchaseOrder $po, array $lines, bool $canCost): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('procurement.error.lines_required')]);
        }
        $kept = $po->lines()->get()->keyBy('id');
        $po->lines()->delete();

        foreach ($lines as $i => $input) {
            $cost = $canCost
                ? ($input['unit_cost_minor'] ?? null)
                : (isset($input['id']) ? $kept->get((int) $input['id'])?->unit_cost_minor : null);
            $this->create($po, $i + 1, $input, $cost);
        }
        PurchaseAmounts::recalculate($po);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function create(PurchaseOrder $po, int $no, array $input, ?int $cost): void
    {
        $type = (string) ($input['item_type'] ?? '');
        $id = (int) ($input['item_id'] ?? 0);
        $quantity = (int) ($input['quantity_milli'] ?? 0);
        $field = 'lines.'.($no - 1);
        if ($quantity < 1 || $quantity > Quantity::MAX_MILLI) {
            throw ValidationException::withMessages([$field.'.quantity' => __('procurement.error.quantity')]);
        }
        [$name, $unit] = $this->item($type, $id, $field);

        $orderId = isset($input['order_id']) && $input['order_id'] !== '' ? (int) $input['order_id'] : null;
        // Only an order of the purchase's own branch (no cross-branch link, no existence oracle on other branches).
        if ($orderId !== null && ! Order::query()->whereKey($orderId)->where('branch_id', $po->branch_id)->exists()) {
            throw ValidationException::withMessages([$field.'.order_id' => __('procurement.error.order_missing')]);
        }

        $line = new PurchaseOrderLine([
            'purchase_order_id' => $po->id, 'line_no' => $no, 'item_type' => $type, 'item_id' => $id,
            'item_name' => $name, 'unit' => $unit, 'quantity_milli' => $quantity, 'unit_cost_minor' => $cost, 'order_id' => $orderId,
        ]);
        PurchaseAmounts::priceLine($line, PurchaseAmounts::vat($po));
        $line->save();
    }

    /**
     * @return array{0: Translated, 1: string}
     */
    private function item(string $type, int $id, string $field): array
    {
        if ($type === PurchaseOrderLine::MATERIAL) {
            $material = CatalogMaterial::query()->find($id);
            if ($material === null || $material->isDisabled()) {
                throw ValidationException::withMessages([$field.'.item_id' => __('procurement.error.item_unavailable')]);
            }

            return [$material->name, $material->unit];
        }
        if ($type === PurchaseOrderLine::READY_VARIANT) {
            $variant = CatalogReadyVariant::query()->with('product')->find($id);
            if ($variant === null || $variant->isDisabled()) {
                throw ValidationException::withMessages([$field.'.item_id' => __('procurement.error.item_unavailable')]);
            }
            $name = [];
            foreach (['ar', 'en'] as $locale) {
                $product = $variant->product->name->only($locale);
                $own = $variant->name->only($locale);
                $name[$locale] = $product === null && $own === null ? null : trim(($product ?? '').($product !== null && $own !== null ? ' — ' : '').($own ?? ''));
            }

            return [Translated::fromArray($name), self::PIECE];
        }

        throw ValidationException::withMessages([$field.'.item_type' => __('procurement.error.item_unavailable')]);
    }
}
