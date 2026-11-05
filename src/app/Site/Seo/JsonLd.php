<?php

namespace App\Site\Seo;

use App\Models\Facility;
use App\Models\FinanceSettings;
use App\Models\Marketing\MarketingSettings;
use App\Money\Money;
use App\Site\SiteContext;

/**
 * schema.org structured data of the public surface (ADR-0045): the facility as a LocalBusiness, products with their
 * offers (price as the visitor pays it, VAT included), breadcrumbs and FAQ sections. Encoded so no text can close the
 * script element.
 */
final class JsonLd
{
    public function __construct(private readonly SiteContext $site) {}

    /**
     * @return array<string, mixed>
     */
    public function business(string $locale, ?string $logo): array
    {
        $facility = Facility::current();
        $base = $this->site->baseUrl();

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            '@id' => $base.'/#business',
            'name' => $this->site->facilityName($locale),
            'url' => $base.'/'.$locale,
            'logo' => $logo === null ? null : $this->absolute($logo),
            'image' => $logo === null ? null : $this->absolute($logo),
            'telephone' => $facility?->contact_phone,
            'email' => $facility?->contact_email,
            'address' => $facility?->address ? ['@type' => 'PostalAddress', 'streetAddress' => $facility->address, 'addressCountry' => 'SA'] : null,
            'currenciesAccepted' => strtoupper((string) ($facility->currency ?? 'SAR')),
            // The facility's own profiles (Instagram, Google Business…), so search engines tie them to the business.
            'sameAs' => array_values(MarketingSettings::current()->profiles()) ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * A store product (StoreCatalogue::find) with one Offer, or an AggregateOffer over several variants.
     *
     * @param  array<string, mixed>  $product
     * @param  list<string>  $images  absolute or root-relative image URLs
     * @return array<string, mixed>
     */
    public function product(array $product, string $url, array $images): array
    {
        $currency = strtoupper((string) (Facility::current()->currency ?? 'SAR'));
        $availability = 'https://schema.org/'.($product['available'] > 0 ? 'InStock' : 'OutOfStock');
        $variants = $product['variants'];
        $offer = count($variants) > 1
            ? ['@type' => 'AggregateOffer', 'lowPrice' => self::gross((int) $product['from_minor']), 'highPrice' => self::gross((int) $product['to_minor']), 'offerCount' => count($variants)]
            : ['@type' => 'Offer', 'price' => self::gross((int) $product['from_minor'])];

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $url.'#product',
            'name' => $product['name'],
            'description' => $product['description'] !== '' ? $product['description'] : null,
            'sku' => $product['default_code'] ?: null,
            'image' => $images === [] ? null : array_map(fn (string $i) => $this->absolute($i), $images),
            'brand' => ['@type' => 'Brand', 'name' => $this->site->facilityName('ar')],
            'offers' => $offer + ['priceCurrency' => $currency, 'availability' => $availability, 'url' => $url, 'seller' => ['@id' => $this->site->baseUrl().'/#business']],
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  list<array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $items): array
    {
        $list = [];
        foreach (array_values($items) as $i => $item) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $item['name'], 'item' => $item['url']];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    /**
     * FAQPage from the visible `faq` sections of a page, or null when it has none.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>|null
     */
    public static function faq(array $sections): ?array
    {
        $questions = [];
        foreach ($sections as $section) {
            if ($section['type'] !== 'faq') {
                continue;
            }
            foreach ($section['data']['items'] ?? [] as $item) {
                if (trim((string) ($item['question'] ?? '')) !== '' && trim((string) ($item['answer'] ?? '')) !== '') {
                    $questions[] = ['@type' => 'Question', 'name' => $item['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']]];
                }
            }
        }

        return $questions === [] ? null : ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions];
    }

    /**
     * JSON for a `<script type="application/ld+json">` body: `<`, `>` and `&` escaped so `</script>` can never appear.
     *
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /** The price the visitor pays (VAT included whatever the facility's entry mode), as `45.00`. */
    public static function gross(int $minor): string
    {
        return FinanceSettings::current()->vat()->line(Money::of($minor))->gross->toInput();
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : $this->site->baseUrl().'/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
