<?php

namespace App\EInvoicing;

use App\Agreements\OrderAgreement;
use App\Enums\OrderKind;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Store\StoreCheckout;
use App\Money\Money;
use App\Money\VatCalculator;
use App\Store\CheckoutStatus;
use Illuminate\Support\Collection;

/**
 * The priced lines a tax invoice of an order carries (ADR-0033), taken from the record that fixed the price — never
 * recomputed differently: a made-to-order order's active agreement version (its lines already hold the per-line
 * net/VAT/gross of Q-34/Q-35, plus each line's discount before VAT for the PDF), a store order's paid checkout (coupon included), else a ready order's own lines priced
 * with the facility VAT setting. Totals are sums of the rounded lines (the single rounding policy).
 */
final class InvoiceContent
{
    /**
     * @return array{lines: list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int, discount_minor?: int}>, rate_bp: int, net_minor: int, vat_minor: int, total_minor: int}|null
     */
    public static function forOrder(Order $order): ?array
    {
        if ($order->kind === OrderKind::Custom) {
            return self::fromAgreement($order);
        }

        return self::fromCheckout($order) ?? self::fromLines($order);
    }

    /**
     * A single amount line (credit or debit note) split at the original rate, VAT-inclusive (Q-35 a).
     *
     * @return array{lines: list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int, discount_minor?: int}>, rate_bp: int, net_minor: int, vat_minor: int, total_minor: int}
     */
    public static function amountLine(string $description, int $grossMinor, int $rateBp): array
    {
        $vat = Money::of($grossMinor)->ratio($rateBp, Money::BP + $rateBp)->minor;
        $line = ['description' => $description, 'quantity' => '1', 'unit' => null, 'net_minor' => $grossMinor - $vat, 'vat_minor' => $vat, 'gross_minor' => $grossMinor];

        return ['lines' => [$line], 'rate_bp' => $rateBp, 'net_minor' => $line['net_minor'], 'vat_minor' => $vat, 'total_minor' => $grossMinor];
    }

    /**
     * @return array{lines: list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int, discount_minor?: int}>, rate_bp: int, net_minor: int, vat_minor: int, total_minor: int}|null
     */
    private static function fromAgreement(Order $order): ?array
    {
        $agreement = OrderAgreement::current($order);
        if ($agreement === null) {
            return null;
        }
        $content = $agreement->content;
        $rate = (int) ($content['vat']['rate_bp'] ?? FinanceSettings::current()->vat_rate_bp);
        $vat = new VatCalculator($rate, (bool) ($content['vat']['inclusive'] ?? FinanceSettings::current()->prices_include_vat));
        $lines = [];
        foreach ((array) ($content['lines'] ?? []) as $line) {
            if (! is_array($line) || ! isset($line['net_minor'], $line['vat_minor'], $line['gross_minor'])) {
                return null;
            }
            $lines[] = [
                'description' => mb_substr((string) ($line['description'] ?? '—'), 0, 500),
                'quantity' => (string) ($line['quantity'] ?? '1'),
                'unit' => isset($line['unit']) ? (string) $line['unit'] : null,
                'net_minor' => (int) $line['net_minor'],
                'vat_minor' => (int) $line['vat_minor'],
                'gross_minor' => (int) $line['gross_minor'],
            ] + self::discount((int) ($line['discount_minor'] ?? 0), (int) ($line['after_discount_minor'] ?? 0), (int) $line['net_minor'], $vat);
        }

        return self::totals($lines, $rate);
    }

    /**
     * @return array{lines: list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int, discount_minor?: int}>, rate_bp: int, net_minor: int, vat_minor: int, total_minor: int}|null
     */
    private static function fromCheckout(Order $order): ?array
    {
        $checkout = StoreCheckout::query()->where('order_id', $order->id)->where('status', CheckoutStatus::Paid->value)->first();
        if ($checkout === null) {
            return null;
        }
        $pricing = $checkout->pricing;
        $names = self::activeLines($order)->values();
        $lines = [];
        foreach (array_values((array) ($pricing['lines'] ?? [])) as $i => $line) {
            // StorePricing names the line's gross `total_minor`; its coupon share is already before VAT.
            if (! is_array($line) || ! isset($line['net_minor'], $line['vat_minor'], $line['total_minor'])) {
                return null;
            }
            $orderLine = $names->get($i);
            $discount = (int) ($line['discount_minor'] ?? 0);
            $lines[] = [
                'description' => $orderLine instanceof OrderLine ? self::name($orderLine) : '—',
                'quantity' => (string) ($line['quantity'] ?? '1'),
                'unit' => null,
                'net_minor' => (int) $line['net_minor'],
                'vat_minor' => (int) $line['vat_minor'],
                'gross_minor' => (int) $line['total_minor'],
            ] + ($discount > 0 ? ['discount_minor' => $discount] : []);
        }

        return self::totals($lines, (int) ($pricing['vat']['rate_bp'] ?? FinanceSettings::current()->vat_rate_bp));
    }

    /**
     * @return array{lines: list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int, discount_minor?: int}>, rate_bp: int, net_minor: int, vat_minor: int, total_minor: int}|null
     */
    private static function fromLines(Order $order): ?array
    {
        $vat = FinanceSettings::current()->vat();
        $lines = [];
        foreach (self::activeLines($order) as $line) {
            if ($line->line_total_minor === null) {
                return null;
            }
            $breakdown = $vat->line(Money::of($line->line_total_minor));
            $lines[] = [
                'description' => self::name($line),
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit,
            ] + $breakdown->toArray();
        }

        return self::totals($lines, $vat->rateBp());
    }

    /**
     * An agreement line's discount before VAT (shown on the PDF; the XML carries the net after it): the discount is
     * taken from the line amount, so with inclusive prices its pre-VAT part is net(before) − net(after).
     *
     * @return array{discount_minor?: int}
     */
    private static function discount(int $discountMinor, int $afterMinor, int $netMinor, VatCalculator $vat): array
    {
        if ($discountMinor <= 0) {
            return [];
        }
        $before = $vat->line(Money::of($afterMinor + $discountMinor))->net->minor;

        return ['discount_minor' => max(0, $before - $netMinor)];
    }

    /**
     * @return Collection<int, OrderLine>
     */
    private static function activeLines(Order $order): Collection
    {
        return OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->orderBy('position')->get();
    }

    private static function name(OrderLine $line): string
    {
        if (is_string($line->description) && trim($line->description) !== '') {
            return mb_substr(trim($line->description), 0, 500);
        }
        $item = $line->snapshot['item'] ?? [];
        $name = is_array($item) ? ($item['name'] ?? $item['product_name'] ?? null) : null;
        if (is_array($name)) {
            $name = $name['ar'] ?? reset($name);
        }
        $variant = is_array($item) ? ($item['label'] ?? $item['variant_name'] ?? null) : null;
        if (is_array($variant)) {
            $variant = $variant['ar'] ?? reset($variant);
        }
        $text = trim(implode(' — ', array_filter([is_string($name) ? $name : null, is_string($variant) ? $variant : null])));

        return $text === '' ? '—' : mb_substr($text, 0, 500);
    }

    /**
     * @param  list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int, discount_minor?: int}>  $lines
     * @return array{lines: list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int, discount_minor?: int}>, rate_bp: int, net_minor: int, vat_minor: int, total_minor: int}|null
     */
    private static function totals(array $lines, int $rateBp): ?array
    {
        if ($lines === []) {
            return null;
        }

        return [
            'lines' => $lines,
            'rate_bp' => $rateBp,
            'net_minor' => array_sum(array_column($lines, 'net_minor')),
            'vat_minor' => array_sum(array_column($lines, 'vat_minor')),
            'total_minor' => array_sum(array_column($lines, 'gross_minor')),
        ];
    }
}
