<?php

namespace App\Http\Controllers\Catalog;

use App\Access\Permission;
use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SetCatalogStatus;
use App\Catalog\CatalogStatus;
use App\Catalog\MinorUnits;
use App\Catalog\PricingBasis;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Made-to-order products (COM-04, P-11): paginated list with filters, create/edit (an edit that changes the
 * specification writes a new version), disable/enable. Nothing is deleted.
 */
final class ProductController extends CatalogController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P11);

        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'disabled'], true) ? (string) $request->query('status') : null;
        $category = $request->filled('category') ? (string) $request->query('category') : null;

        $products = CatalogProduct::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', $this->like($q))->orWhere('category', 'like', $this->like($q))))
            ->orderBy('status')->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (CatalogProduct $p) => $this->presenter->productRow($p));

        return Inertia::render('catalog/products/index', [
            'products' => $products,
            'filters' => ['q' => $q, 'status' => $status, 'category' => $category],
            'categories' => CatalogProduct::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all(),
            'tabs' => $this->tabs($actor),
            'canCreate' => $this->access->can($actor, Permission::P11, new CatalogProduct),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->access->authorize($this->actor($request), Permission::P11, new CatalogProduct);

        return $this->form($request, null);
    }

    public function store(ProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $product = $save->handle($this->actor($request), null, $request->catalogData());
        $this->toast(__('catalog.product.created', ['name' => $product->name->get()]));

        return to_route('catalog.products.edit', $product);
    }

    public function edit(Request $request, CatalogProduct $product): Response
    {
        $this->access->authorize($this->actor($request), Permission::P11, $product);

        return $this->form($request, $product);
    }

    public function update(ProductRequest $request, CatalogProduct $product, SaveProduct $save): RedirectResponse
    {
        $save->handle($this->actor($request), $product, $request->catalogData());
        $this->toast(__('catalog.product.updated'));

        return to_route('catalog.products.edit', $product);
    }

    public function disable(Request $request, CatalogProduct $product, SetCatalogStatus $status): RedirectResponse
    {
        $status->handle($this->actor($request), $product, CatalogStatus::Disabled);
        $this->toast(__('catalog.product.disabled', ['name' => $product->name->get()]));

        return to_route('catalog.products.edit', $product);
    }

    public function enable(Request $request, CatalogProduct $product, SetCatalogStatus $status): RedirectResponse
    {
        $status->handle($this->actor($request), $product, CatalogStatus::Active);
        $this->toast(__('catalog.product.enabled', ['name' => $product->name->get()]));

        return to_route('catalog.products.edit', $product);
    }

    private function form(Request $request, ?CatalogProduct $product): Response
    {
        $actor = $this->actor($request);

        $materials = CatalogMaterial::query()->orderBy('id')->get()->map(fn (CatalogMaterial $m) => [
            'id' => $m->id,
            'name' => $m->name->get(),
            'group' => $m->material_group,
            'selectable' => $m->isSelectable(),
        ])->all();

        return Inertia::render('catalog/products/form', [
            'product' => $product === null ? null : [
                ...$this->presenter->product($product, $actor),
                'reference_price' => $product->reference_price_minor === null ? '' : MinorUnits::toInput($product->reference_price_minor),
            ],
            'materials' => $materials,
            'pricingBases' => array_map(fn (PricingBasis $b) => $b->value, PricingBasis::cases()),
            'tabs' => $this->tabs($actor),
        ]);
    }
}
