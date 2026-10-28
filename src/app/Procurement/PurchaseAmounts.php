<?php

namespace App\Procurement;

use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Money\Money;
use App\Money\VatCalculator;

/**
 * Purchase amounts (halalas): a line is unit cost × quantity (thousandths) rounded half-up once, then VAT per line by the
 * facility policy captured on the order (Q-35 a); the order total is the sum of the rounded lines.
 */
final class PurchaseAmounts
{
    public static function vat(PurchaseOrder $po): VatCalculator
    {
        return new VatCalculator($po->vat_rate_bp, $po->prices_include_vat);
    }

    public static function priceLine(PurchaseOrderLine $line, VatCalculator $vat): void
    {
        if ($line->unit_cost_minor === null) {
            $line->forceFill(['net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0]);

            return;
        }

        $breakdown = $vat->line(Money::of($line->unit_cost_minor)->ratio($line->quantity_milli, 1000));
        $line->forceFill($breakdown->toArray());
    }

    /**
     * Re-prices every line and the order's totals from the lines' cost and quantity. Saves them.
     */
    public static function recalculate(PurchaseOrder $po): void
    {
        $vat = self::vat($po);
        $net = $vatSum = $gross = 0;
        foreach ($po->lines()->get() as $line) {
            self::priceLine($line, $vat);
            $line->save();
            $net += $line->net_minor;
            $vatSum += $line->vat_minor;
            $gross += $line->gross_minor;
        }
        $po->forceFill(['net_minor' => $net, 'vat_minor' => $vatSum, 'gross_minor' => $gross])->save();
    }
}
