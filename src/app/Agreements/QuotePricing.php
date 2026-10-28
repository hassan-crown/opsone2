<?php

namespace App\Agreements;

use App\Models\FinanceSettings;
use App\Money\Money;
use App\Money\VatCalculator;

/**
 * Prices quote/agreement content (AGR-01, Q-34 a, Q-35 a), the one place that turns entered lines into amounts:
 *
 * 1. line amount = unit price × quantity, half-up, as entered (VAT-inclusive or not per the facility setting);
 * 2. discount on the sum of line amounts (percent half-up, or a fixed amount ≤ that sum), allocated to the lines in
 *    proportion to their amounts without losing a halala (Money::allocate);
 * 3. VAT per line on the discounted line amount, half-up; totals are sums of the rounded lines.
 *
 * A line without a price leaves the content incomplete (no unknown amount in a quote ready for approval) and all
 * totals null. `products_minor` sums the commission-eligible lines after discount (SAL-10, INT-06).
 */
final class QuotePricing
{
    public const DISCOUNT_NONE = 'none';

    public const DISCOUNT_PERCENT = 'percent';

    public const DISCOUNT_AMOUNT = 'amount';

    /**
     * @param  list<array{order_line_id?: int|null, description: string, quantity: string, unit?: string|null, unit_price_minor: int|null, commissionable?: bool}>  $lines
     * @param  array{type: string, value?: int|null}  $discount  value: basis points for percent, halalas for amount
     * @return array{lines: list<array<string, mixed>>, discount: array{type: string, value: int, amount_minor: int|null}, vat: array{rate_bp: int, inclusive: bool}, totals: array{base_minor: int|null, discount_minor: int|null, net_minor: int|null, vat_minor: int|null, total_minor: int|null, products_minor: int|null}, complete: bool}
     */
    public static function price(array $lines, array $discount, FinanceSettings|VatCalculator $vat): array
    {
        $vat = $vat instanceof FinanceSettings ? $vat->vat() : $vat;
        $type = $discount['type'];
        $value = (int) ($discount['value'] ?? 0);
        if ($type === self::DISCOUNT_NONE) {
            $value = 0;
        }

        $out = [];
        $amounts = [];
        $complete = $lines !== [];
        foreach ($lines as $i => $line) {
            $price = $line['unit_price_minor'];
            $amount = $price === null ? null : Money::of($price)->times($line['quantity']);
            $complete = $complete && $amount !== null;
            $amounts[$i] = $amount;
            $out[$i] = [
                'order_line_id' => $line['order_line_id'] ?? null,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'] ?? null,
                'unit_price_minor' => $price,
                'commissionable' => (bool) ($line['commissionable'] ?? true),
                'amount_minor' => $amount?->minor,
            ];
        }

        $empty = ['base_minor' => null, 'discount_minor' => null, 'net_minor' => null, 'vat_minor' => null, 'total_minor' => null, 'products_minor' => null];
        $vatInfo = ['rate_bp' => $vat->rateBp(), 'inclusive' => $vat->inclusive()];
        if (! $complete) {
            return ['lines' => array_values($out), 'discount' => ['type' => $type, 'value' => $value, 'amount_minor' => null], 'vat' => $vatInfo, 'totals' => $empty, 'complete' => false];
        }

        /** @var list<Money> $amounts */
        $base = Money::sum($amounts);
        $discountAmount = match ($type) {
            self::DISCOUNT_PERCENT => $base->percent($value),
            self::DISCOUNT_AMOUNT => Money::of($value),
            default => Money::zero(),
        };
        $shares = $discountAmount->allocate(array_map(fn (Money $m) => $m->minor, $amounts));

        $net = $tax = $gross = $products = [];
        foreach ($amounts as $i => $amount) {
            $after = $amount->minus($shares[$i]);
            $breakdown = $vat->line($after);
            $out[$i] += ['discount_minor' => $shares[$i]->minor, 'after_discount_minor' => $after->minor] + $breakdown->toArray();
            $net[] = $breakdown->net;
            $tax[] = $breakdown->vat;
            $gross[] = $breakdown->gross;
            if ($out[$i]['commissionable']) {
                $products[] = $vat->inclusive() ? $breakdown->gross : $breakdown->net;
            }
        }

        return [
            'lines' => array_values($out),
            'discount' => ['type' => $type, 'value' => $value, 'amount_minor' => $discountAmount->minor],
            'vat' => $vatInfo,
            'totals' => [
                'base_minor' => $base->minor,
                'discount_minor' => $discountAmount->minor,
                'net_minor' => Money::sum($net)->minor,
                'vat_minor' => Money::sum($tax)->minor,
                'total_minor' => Money::sum($gross)->minor,
                'products_minor' => $products === [] ? 0 : Money::sum($products)->minor,
            ],
            'complete' => true,
        ];
    }
}
