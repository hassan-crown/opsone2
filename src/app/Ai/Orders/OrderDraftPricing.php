<?php

namespace App\Ai\Orders;

use App\Catalog\MinorUnits;
use App\Money\Money;
use App\Money\MoneyOverflow;
use App\Money\Rounding;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;

/**
 * Every amount on a conversation draft, computed by the server from the catalog as it is now (AI-5, «السعر من
 * الكتالوج لا من النموذج»). Nothing here reads the model's output or the conversation: only the catalog item, the
 * quantity and the sizes on the line.
 *
 * - Ready item: the variant's current price × whole pieces — exactly what OrderLineBuilder freezes on the line.
 * - Made-to-order product: an indicative amount from its reference price and pricing basis (per piece, per linear
 *   metre of width, per square metre of width × height). The custom order still opens WITHOUT a price (COM-08); the
 *   agreed price and any discount are set later in the quote, so no discount is ever applied here.
 * - Rounded half-up once per line (Q-34 a) on integer halalas; never a float.
 */
final class OrderDraftPricing
{
    public static function unitFor(?string $basis): ?string
    {
        return match ($basis) {
            'linear_meter' => 'meter',
            'square_meter' => 'square_meter',
            'piece' => 'piece',
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $lines  form lines: type, product_id|variant_id, quantity, unit, width_cm, height_cm
     * @return array{lines: array<string, array{unit_price_minor: int|null, line_total_minor: int|null, reference_minor: int|null, note: string|null}>, ready_total_minor: int, reference_total_minor: int, reference_partial: bool}
     */
    public static function price(array $lines): array
    {
        $out = [];
        $ready = 0;
        $reference = 0;
        $partial = false;

        foreach ($lines as $i => $line) {
            $key = (string) ($line['key'] ?? $i);
            $row = ['unit_price_minor' => null, 'line_total_minor' => null, 'reference_minor' => null, 'note' => null];
            $type = $line['type'] ?? null;

            if ($type === 'ready_variant') {
                $row = self::ready($line, $row);
                $ready += (int) $row['line_total_minor'];
                if ($ready > MinorUnits::MAX) {
                    $ready = MinorUnits::MAX;
                    $row['note'] = 'too_large';
                }
            } elseif ($type === 'custom_product') {
                $row = self::custom($line, $row);
                if ($row['reference_minor'] === null) {
                    $partial = true;
                } else {
                    $reference = min(MinorUnits::MAX, $reference + $row['reference_minor']);
                }
            } elseif ($type === 'custom_need') {
                $row['note'] = 'no_price';
                $partial = true;
            } else {
                $row['note'] = 'needs_choice';
            }

            $out[$key] = $row;
        }

        return ['lines' => $out, 'ready_total_minor' => $ready, 'reference_total_minor' => $reference, 'reference_partial' => $partial];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array{unit_price_minor: int|null, line_total_minor: int|null, reference_minor: int|null, note: string|null}  $row
     * @return array{unit_price_minor: int|null, line_total_minor: int|null, reference_minor: int|null, note: string|null}
     */
    private static function ready(array $line, array $row): array
    {
        $variant = CatalogReadyVariant::query()->with('product')->find((int) ($line['variant_id'] ?? 0));
        if ($variant === null || ! $variant->isSelectable()) {
            return ['note' => 'unavailable'] + $row;
        }
        $row['unit_price_minor'] = (int) $variant->price_minor;

        $quantity = (string) ($line['quantity'] ?? '');
        if (preg_match('/^[1-9]\d{0,5}$/', $quantity) !== 1) {
            return ['note' => 'whole_quantity'] + $row;
        }

        try {
            $row['line_total_minor'] = Money::of((int) $variant->price_minor)->times($quantity)->minor;
        } catch (MoneyOverflow) {
            $row['note'] = 'too_large';
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array{unit_price_minor: int|null, line_total_minor: int|null, reference_minor: int|null, note: string|null}  $row
     * @return array{unit_price_minor: int|null, line_total_minor: int|null, reference_minor: int|null, note: string|null}
     */
    private static function custom(array $line, array $row): array
    {
        $product = CatalogProduct::query()->find((int) ($line['product_id'] ?? 0));
        if ($product === null || ! $product->isSelectable()) {
            return ['note' => 'unavailable'] + $row;
        }
        $price = $product->reference_price_minor;
        $basis = self::unitFor($product->pricing_basis->value);
        if ($price === null || $basis === null) {
            return ['note' => 'estimate'] + $row;
        }
        $row['unit_price_minor'] = (int) $price;

        $quantity = OrderDraftLines::quantity($line['quantity'] ?? '1') ?? '1';
        $unit = $line['unit'] ?? null;
        $width = OrderDraftLines::cmToMm($line['width_cm'] ?? null);
        $height = OrderDraftLines::cmToMm($line['height_cm'] ?? null);

        try {
            if ($basis === 'piece' || ($unit === $basis)) {
                // Per piece, or a length/area the customer already gave in the product's own unit.
                $row['reference_minor'] = Money::of((int) $price)->times($quantity)->minor;
            } elseif ($basis === 'meter' && $width !== null) {
                // price × width(mm)/1000 × pieces, in thousandths of a piece.
                $row['reference_minor'] = Rounding::divide(bcmul(bcmul((string) $price, (string) $width), bcmul($quantity, '1000', 0)), '1000000');
            } elseif ($basis === 'square_meter' && $width !== null && $height !== null) {
                $row['reference_minor'] = Rounding::divide(bcmul(bcmul(bcmul((string) $price, (string) $width), (string) $height), bcmul($quantity, '1000', 0)), '1000000000');
            } else {
                $row['note'] = 'needs_measurement';
            }
        } catch (MoneyOverflow) {
            $row['note'] = 'too_large';
        }

        return $row;
    }
}
