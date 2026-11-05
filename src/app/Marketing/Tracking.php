<?php

namespace App\Marketing;

use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Facility;
use App\Models\Store\StoreCheckout;
use App\Money\Money;
use App\Site\Seo\JsonLd;
use App\Store\CheckoutStatus;
use App\Support\RequestCache;
use Illuminate\Support\Str;

/**
 * Standard marketing events of the public site and store (ADR-0045), in the GA4 e-commerce shape; the browser loader
 * (`resources/js/site-analytics.ts`) maps them to each configured platform, after consent.
 *
 * Privacy rule: an event never carries a name, mobile, email, address or customer id — only products, amounts, the
 * currency, the coupon code and the order number. Every event has an `id` the platforms use to drop duplicates.
 * Events of a redirecting POST (add to cart, lead, appointment request) are flashed to the next page.
 */
final class Tracking
{
    public const FLASH = 'marketing.events';

    /**
     * @param  array<string, mixed>  $params
     * @return array{name: string, id: string, params: array<string, mixed>}
     */
    public static function event(string $name, array $params = [], ?string $id = null): array
    {
        return ['name' => $name, 'id' => $id ?? (string) Str::uuid(), 'params' => $params];
    }

    /**
     * Queues an event for the page the visitor lands on after a redirect.
     *
     * @param  array<string, mixed>  $params
     */
    public static function flash(string $name, array $params = []): void
    {
        $events = session()->get(self::FLASH, []);
        $events[] = self::event($name, $params);
        session()->flash(self::FLASH, $events);
    }

    /**
     * @return list<array{name: string, id: string, params: array<string, mixed>}>
     */
    public static function flashed(): array
    {
        $events = session()->has(self::FLASH) ? session()->get(self::FLASH) : [];

        return is_array($events) ? array_values($events) : [];
    }

    /**
     * One GA4 item: the variant code is the item id (the same as the product feed).
     *
     * @return array<string, mixed>
     */
    public static function item(string $code, string $name, ?string $variant, int $unitMinor, int $quantity = 1): array
    {
        return array_filter([
            'item_id' => $code, 'item_name' => $name, 'item_variant' => $variant !== '' ? $variant : null,
            'price' => self::number(JsonLd::gross($unitMinor)), 'quantity' => $quantity,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function variantItem(CatalogReadyVariant $variant, string $locale, int $quantity = 1): array
    {
        return self::item($variant->code, (string) $variant->product->name->get($locale), (string) $variant->name->get($locale), $variant->price_minor, $quantity);
    }

    /**
     * The e-commerce block. Amounts are worked out in halalas (Money) and only turned into a JSON number here, for the
     * platforms; they never come back into the app.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function commerce(array $items, int $valueMinor): array
    {
        return ['currency' => self::currency(), 'value' => self::number(Money::of($valueMinor)->toInput()), 'items' => $items];
    }

    /** What the visitor pays for `quantity` of a unit price, VAT included, in halalas. */
    public static function grossMinor(int $unitMinor, int $quantity = 1): int
    {
        return JsonLd::vat()->line(Money::of($unitMinor)->times((string) $quantity))->gross->minor;
    }

    /**
     * `purchase` of a paid checkout, once: the first view of the paid page claims it with a conditional update, so a
     * reload, a second tab or the auto-refresh never sends it again. Null when not paid or already sent.
     *
     * @return array{name: string, id: string, params: array<string, mixed>}|null
     */
    public static function purchaseOnce(StoreCheckout $checkout, string $locale): ?array
    {
        if ($checkout->status !== CheckoutStatus::Paid) {
            return null;
        }
        $claimed = StoreCheckout::query()->whereKey($checkout->id)->whereNull('purchase_tracked_at')->update(['purchase_tracked_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }

        $variants = CatalogReadyVariant::query()->with('product')->whereIn('id', array_column($checkout->pricing['lines'] ?? [], 'variant_id'))->get()->keyBy('id');
        $items = [];
        foreach ($checkout->pricing['lines'] ?? [] as $line) {
            $variant = $variants[(int) $line['variant_id']] ?? null;
            if ($variant !== null) {
                $items[] = self::variantItem($variant, $locale, (int) $line['quantity']);
            }
        }

        return self::event('purchase', array_filter([
            ...self::commerce($items, $checkout->total_minor),
            'transaction_id' => $checkout->number,
            'tax' => self::number(Money::of($checkout->vat_minor)->toInput()),
            'coupon' => $checkout->coupon?->code,
        ], fn ($v) => $v !== null), $checkout->number);
    }

    /** The facility's currency code, read once per request. */
    public static function currency(): string
    {
        return app(RequestCache::class)->remember('facility.currency', fn () => strtoupper((string) (Facility::current()->currency ?? 'SAR')));
    }

    private static function number(string $decimal): float
    {
        return (float) $decimal;
    }
}
