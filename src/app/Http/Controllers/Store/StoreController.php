<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Facility;
use App\Site\Seo\JsonLd;
use App\Site\Seo\Slug;
use App\Site\SiteContext;
use App\Store\Cart;
use App\Store\StoreCatalogue;
use App\Store\StoreGate;
use App\Store\StoreView;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * StoreList, StoreProduct and StoreCart (WEB-05, P-83 visitor): published ready goods with availability, and the
 * session cart. Hidden (404) whenever the store is not selling (license without `ready_goods`, store closed).
 */
final class StoreController extends Controller
{
    public function __construct(
        private readonly StoreGate $gate,
        private readonly StoreCatalogue $catalogue,
        private readonly StoreView $view,
        private readonly Cart $cart,
        private readonly SiteContext $site,
        private readonly JsonLd $jsonLd,
    ) {}

    public function index(string $locale): View
    {
        $this->gate->abortUnlessOpen();
        $title = __('store.public.title', [], $locale);
        $alternates = $this->alternates(fn (string $l) => $this->site->baseUrl().'/'.$l.'/store');

        return $this->view->render('store.index', $locale, $title, [
            'products' => $this->catalogue->products($locale),
        ], seo: [
            'description' => __('store.public.meta_description', ['name' => $this->site->facilityName($locale)], $locale),
            'canonical' => $alternates[$locale],
            'alternates' => $alternates,
            'json_ld' => [JsonLd::encode(JsonLd::breadcrumbs([
                ['name' => $this->site->facilityName($locale), 'url' => $this->site->url($locale)],
                ['name' => $title, 'url' => $alternates[$locale]],
            ]))],
        ]);
    }

    /**
     * StoreProduct (ADR-0045): the id finds the product; any other slug than the current one answers 301 to the
     * canonical URL (query string kept, so campaign tags survive). A product taken off sale is 410, an unknown one 404.
     */
    public function product(Request $request, string $locale, int $product, ?string $slug = null): View|RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        $item = $this->catalogue->find($product, $locale);
        if ($item === null) {
            abort_if(CatalogReadyProduct::query()->find($product)?->isDisabled() === true, 410);
            abort(404);
        }
        if ($item['slug'] !== '' && $slug !== $item['slug']) {
            $query = $request->getQueryString();

            return redirect(StoreCatalogue::path($locale, $product, $item['slug']).($query ? '?'.$query : ''), 301);
        }

        $alternates = $this->alternates(fn (string $l) => $this->site->baseUrl().StoreCatalogue::path($l, $product, $item['slugs'][$l]));
        $images = array_map(fn (string $uuid) => route('site.media', ['attachment' => $uuid]), $item['images']);
        $storeUrl = $this->site->baseUrl().'/'.$locale.'/store';

        return $this->view->render('store.product', $locale, $item['seo_title'], ['product' => $item, 'images' => $images], seo: [
            'description' => $item['seo_description'],
            'canonical' => $alternates[$locale],
            'alternates' => $alternates,
            'og_type' => 'product',
            'image' => $images[0] ?? null,
            'image_alt' => $item['name'],
            'product' => ['amount' => JsonLd::gross((int) $item['from_minor']), 'currency' => strtoupper((string) (Facility::current()->currency ?? 'SAR'))],
            'json_ld' => [
                JsonLd::encode($this->jsonLd->product($item, $alternates[$locale], $images)),
                JsonLd::encode(JsonLd::breadcrumbs([
                    ['name' => $this->site->facilityName($locale), 'url' => $this->site->url($locale)],
                    ['name' => __('store.public.title', [], $locale), 'url' => $storeUrl],
                    ['name' => $item['name'], 'url' => $alternates[$locale]],
                ])),
            ],
        ]);
    }

    /**
     * @param  callable(string): string  $url
     * @return array<string, string>
     */
    private function alternates(callable $url): array
    {
        $out = ['ar' => $url('ar'), 'en' => $url('en')];

        return $out + ['x-default' => $out[$this->site->defaultLocale()]];
    }

    public function cart(string $locale): View
    {
        $this->gate->abortUnlessOpen();

        return $this->view->render('store.cart', $locale, __('store.public.cart'), ['lines' => $this->cartLines($locale)], false);
    }

    public function add(Request $request, string $locale): RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        $data = $request->validate(['variant_id' => ['required', 'integer'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:'.Cart::MAX_QUANTITY]]);
        $variant = $this->catalogue->variant((int) $data['variant_id']);
        if ($variant === null) {
            return back()->withErrors(['variant_id' => __('checkout.validation.unavailable')]);
        }
        $this->cart->add($variant->id, (int) ($data['quantity'] ?? 1));

        return redirect()->route('store.cart', ['locale' => $locale])->with('status', __('store.public.added'));
    }

    public function update(Request $request, string $locale, int $variant): RedirectResponse
    {
        $this->gate->abortUnlessOpen();
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:'.Cart::MAX_QUANTITY]]);
        $this->cart->set($variant, (int) $data['quantity']);

        return redirect()->route('store.cart', ['locale' => $locale]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cartLines(string $locale): array
    {
        $lines = $this->cart->lines();
        $available = $this->catalogue->availability(array_keys($lines));
        $out = [];
        foreach ($lines as $variantId => $quantity) {
            $variant = $this->catalogue->variant($variantId);
            if ($variant === null) {
                continue;
            }
            $out[] = [
                'variant_id' => $variantId,
                'name' => (string) $variant->product->name->get($locale),
                'variant' => (string) $variant->name->get($locale),
                'product_id' => $variant->ready_product_id,
                'product_path' => StoreCatalogue::path($locale, $variant->ready_product_id, $variant->product->slug->only($locale) ?: Slug::make($variant->product->name->get($locale))),
                'quantity' => $quantity,
                'unit_minor' => $variant->price_minor,
                'line_minor' => $variant->price_minor * $quantity,
                'available' => $available[$variantId] ?? 0,
            ];
        }

        return $out;
    }
}
