<?php

namespace App\Orders;

use App\Catalog\MinorUnits;
use App\Enums\OrderKind;
use App\Enums\OrderLineType;
use App\Models\Catalog\CatalogItem;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Catalog\CatalogVersion;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Turns validated line input into an unsaved OrderLine whose specification is frozen now (COM-11): the current
 * catalog version id and payload, the chosen options and, for ready goods, the variant price at this moment.
 * Later catalog edits write new versions and never reach the line.
 */
final class OrderLineBuilder
{
    /**
     * @param  array<string, mixed>  $input  one validated line (see OrderLineRules)
     */
    public function build(Order $order, array $input, User $actor, int $position, string $errorKey = 'line'): OrderLine
    {
        $type = OrderLineType::from((string) $input['type']);
        if ($type->kind() !== $order->kind) {
            throw ValidationException::withMessages([$errorKey => __('orders.validation.line_kind')]);
        }

        $line = new OrderLine([
            'order_id' => $order->id,
            'position' => $position,
            'line_type' => $type,
            'description' => self::text($input['description'] ?? null),
            'unit' => self::text($input['unit'] ?? null),
            'added_by' => $actor->id,
        ]);

        return match ($type) {
            OrderLineType::CustomNeed => $this->need($line, $input, $errorKey),
            OrderLineType::CustomProduct => $this->customProduct($line, $input, $errorKey),
            OrderLineType::ReadyVariant => $this->readyVariant($line, $input, $errorKey),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function need(OrderLine $line, array $input, string $errorKey): OrderLine
    {
        if ($line->description === null) {
            throw ValidationException::withMessages([$errorKey.'.description' => __('orders.validation.need_description')]);
        }

        $line->forceFill([
            'quantity' => (string) ($input['quantity'] ?? '1'),
            'snapshot' => ['options' => []],
        ]);

        return $line;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function customProduct(OrderLine $line, array $input, string $errorKey): OrderLine
    {
        $product = CatalogProduct::query()->find((int) ($input['product_id'] ?? 0));
        if ($product === null || ! $product->isSelectable()) {
            throw ValidationException::withMessages([$errorKey.'.product_id' => __('orders.validation.item_unavailable')]);
        }

        $groups = collect($product->option_groups ?? [])->keyBy('key');
        $options = [];
        foreach ($input['options'] ?? [] as $option) {
            $key = (string) ($option['key'] ?? '');
            if (! $groups->has($key)) {
                throw ValidationException::withMessages([$errorKey.'.options' => __('orders.validation.unknown_option')]);
            }
            $options[] = ['key' => $key, 'label' => $groups[$key]['label'] ?? null, 'value' => (string) $option['value']];
        }

        $version = $this->currentVersion($product);
        $line->forceFill([
            'catalog_version_id' => $version->id,
            'catalog_item_type' => $product->getMorphClass(),
            'catalog_item_id' => $product->id,
            'quantity' => (string) ($input['quantity'] ?? '1'),
            // The custom order opens without a price (COM-08): the reference price stays in the snapshot only.
            'snapshot' => ['version' => $version->version, 'item' => $version->payload, 'options' => $options],
        ]);

        return $line;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function readyVariant(OrderLine $line, array $input, string $errorKey): OrderLine
    {
        $variant = CatalogReadyVariant::query()->with('product')->find((int) ($input['variant_id'] ?? 0));
        if ($variant === null || ! $variant->isSelectable()) {
            throw ValidationException::withMessages([$errorKey.'.variant_id' => __('orders.validation.item_unavailable')]);
        }

        $quantity = (int) ($input['quantity'] ?? 0);
        if ($quantity < 1 || (string) $quantity !== (string) ($input['quantity'] ?? '')) {
            throw ValidationException::withMessages([$errorKey.'.quantity' => __('orders.validation.whole_quantity')]);
        }

        $version = $this->currentVersion($variant);
        $price = (int) $version->payload['price_minor'];
        if ($price * $quantity > MinorUnits::MAX) {
            throw ValidationException::withMessages([$errorKey.'.quantity' => __('orders.validation.total_too_large')]);
        }
        $line->forceFill([
            'catalog_version_id' => $version->id,
            'catalog_item_type' => $variant->getMorphClass(),
            'catalog_item_id' => $variant->id,
            'quantity' => (string) $quantity,
            'unit_price_minor' => $price,
            'line_total_minor' => $price * $quantity,
            'snapshot' => ['version' => $version->version, 'item' => $version->payload, 'options' => []],
        ]);

        return $line;
    }

    private function currentVersion(Model&CatalogItem $item): CatalogVersion
    {
        $version = CatalogVersion::query()
            ->where('versionable_type', $item->getMorphClass())
            ->where('versionable_id', $item->getKey())
            ->where('version', $item->versionNumber())
            ->first();

        return $version ?? throw ValidationException::withMessages(['line' => __('orders.validation.item_unavailable')]);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Ready orders carry the sum of their active lines; custom orders have no price at opening (null, not zero).
     * Summed as integers and bounded by MinorUnits::MAX, so a huge order is refused instead of saturating.
     */
    public static function total(Order $order, string $errorKey = 'lines'): ?int
    {
        if ($order->kind !== OrderKind::Ready) {
            return null;
        }

        $total = 0;
        foreach ($order->activeLines()->pluck('line_total_minor') as $amount) {
            $total += (int) $amount;
            if ($total > MinorUnits::MAX) {
                throw ValidationException::withMessages([$errorKey => __('orders.validation.total_too_large')]);
            }
        }

        return $total;
    }
}
