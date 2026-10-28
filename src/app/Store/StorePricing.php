<?php

namespace App\Store;

use App\Models\Catalog\CatalogReadyVariant;
use App\Models\FinanceSettings;
use App\Models\Store\Coupon;
use App\Money\Money;
use App\Money\VatCalculator;

/**
 * Store price of ready lines at the variants' current prices (WEB-05) with an optional coupon (SAL-07), on the money
 * rules of ADR-0013 (Q-34 a, Q-35 a): each line amount = unit × quantity; the coupon is computed on the eligible lines'
 * value **before VAT** (their net: the amount less its VAT when prices include VAT) — percent half-up or a fixed amount
 * capped at that value — and allocated to the eligible lines by their net without losing a halala; VAT is then
 * computed per line on the discounted net, half-up, and totals are sums of the rounded lines. A line without discount
 * keeps exactly its entered amount. Each line's discount contribution is kept with the checkout (returns and
 * commission use the actual value, SAL-07).
 */
final class StorePricing
{
    /**
     * @param  list<array{variant: CatalogReadyVariant, quantity: int}>  $lines
     * @return array{lines: list<array<string, int|string|bool>>, totals: array{subtotal_minor: int, discount_minor: int, net_minor: int, vat_minor: int, total_minor: int, eligible_net_minor: int}, vat: array{rate_bp: int, inclusive: bool}}
     */
    public static function price(array $lines, ?Coupon $coupon, ?VatCalculator $vat = null): array
    {
        $vat ??= FinanceSettings::current()->vat();
        $out = [];
        $eligibleNet = [];
        foreach ($lines as $i => $line) {
            $amount = Money::of($line['variant']->price_minor)->times((string) $line['quantity']);
            $breakdown = $vat->line($amount);
            $eligible = $coupon !== null && $coupon->appliesTo($line['variant']->id);
            $out[$i] = [
                'variant_id' => $line['variant']->id,
                'quantity' => $line['quantity'],
                'unit_price_minor' => $line['variant']->price_minor,
                'amount_minor' => $amount->minor,
                'eligible' => $eligible,
            ];
            if ($eligible) {
                $eligibleNet[$i] = $breakdown->net->minor;
            }
        }

        $base = Money::of(array_sum($eligibleNet));
        $discount = match (true) {
            $coupon === null || $base->isZero() => Money::zero(),
            $coupon->kind === Coupon::PERCENT => $base->percent(min(Money::BP, $coupon->value)),
            default => Money::of(min($coupon->value, $base->minor)),
        };
        $shares = $discount->isZero() || $eligibleNet === [] ? [] : array_combine(array_keys($eligibleNet), $discount->allocate(array_values($eligibleNet)));

        $totals = ['subtotal_minor' => 0, 'discount_minor' => $discount->minor, 'net_minor' => 0, 'vat_minor' => 0, 'total_minor' => 0, 'eligible_net_minor' => $base->minor];
        foreach ($out as $i => $row) {
            $amount = Money::of((int) $row['amount_minor']);
            $share = $shares[$i] ?? Money::zero();
            if ($share->isZero()) {
                $b = $vat->line($amount);
                [$net, $tax, $gross] = [$b->net, $b->vat, $b->gross];
            } else {
                $net = $vat->line($amount)->net->minus($share);
                $tax = $net->percent($vat->rateBp());
                $gross = $net->plus($tax);
            }
            $out[$i] += ['discount_minor' => $share->minor, 'net_minor' => $net->minor, 'vat_minor' => $tax->minor, 'total_minor' => $gross->minor];
            $totals['subtotal_minor'] += $amount->minor;
            $totals['net_minor'] += $net->minor;
            $totals['vat_minor'] += $tax->minor;
            $totals['total_minor'] += $gross->minor;
        }

        return ['lines' => array_values($out), 'totals' => $totals, 'vat' => ['rate_bp' => $vat->rateBp(), 'inclusive' => $vat->inclusive()]];
    }
}
