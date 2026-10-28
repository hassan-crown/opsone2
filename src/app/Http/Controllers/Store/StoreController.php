<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
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
    ) {}

    public function index(string $locale): View
    {
        $this->gate->abortUnlessOpen();

        return $this->view->render('store.index', $locale, __('store.public.title'), [
            'products' => $this->catalogue->products($locale),
        ]);
    }

    public function product(string $locale, int $product): View
    {
        $this->gate->abortUnlessOpen();
        $item = $this->catalogue->find($product, $locale) ?? abort(404);

        return $this->view->render('store.product', $locale, $item['name'], ['product' => $item]);
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
                'quantity' => $quantity,
                'unit_minor' => $variant->price_minor,
                'line_minor' => $variant->price_minor * $quantity,
                'available' => $available[$variantId] ?? 0,
            ];
        }

        return $out;
    }
}
