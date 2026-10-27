<?php

namespace App\Http\Controllers\Catalog;

use App\Access\Permission;
use App\Actions\Catalog\SaveReadyProduct;
use App\Actions\Catalog\SaveVariant;
use App\Actions\Catalog\SetCatalogStatus;
use App\Catalog\CatalogStatus;
use App\Http\Requests\Catalog\ReadyProductRequest;
use App\Http\Requests\Catalog\VariantRequest;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ready-goods products and their variants (COM-06, P-12). The Gate also requires the license capability
 * `ready_goods`, so a plan without it cannot open or change anything here.
 */
final class ReadyProductController extends CatalogController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P12);

        $q = trim((string) $request->query('q', ''));
        $status = CatalogStatus::tryFrom((string) $request->query('status'));
        $status = in_array($status, [CatalogStatus::Draft, CatalogStatus::Published, CatalogStatus::Disabled], true) ? $status->value : null;

        $products = CatalogReadyProduct::query()->withCount('variants')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', $this->like($q))
                ->orWhereHas('variants', fn ($v) => $v->where('code', 'like', $this->like($q)))))
            ->orderBy('status')->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (CatalogReadyProduct $p) => $this->presenter->readyRow($p));

        return Inertia::render('catalog/ready/index', [
            'products' => $products,
            'filters' => ['q' => $q, 'status' => $status],
            'tabs' => $this->tabs($actor),
            'canCreate' => $this->access->can($actor, Permission::P12, new CatalogReadyProduct),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P12, new CatalogReadyProduct);

        return Inertia::render('catalog/ready/edit', ['product' => null, 'tabs' => $this->tabs($actor)]);
    }

    public function store(ReadyProductRequest $request, SaveReadyProduct $save): RedirectResponse
    {
        $product = $save->handle($this->actor($request), null, $request->catalogData());
        $this->toast(__('catalog.ready.created', ['name' => $product->name->get()]));

        return to_route('catalog.ready.edit', $product);
    }

    public function edit(Request $request, CatalogReadyProduct $ready): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P12, $ready);

        return Inertia::render('catalog/ready/edit', ['product' => $this->presenter->ready($ready, $actor), 'tabs' => $this->tabs($actor)]);
    }

    public function update(ReadyProductRequest $request, CatalogReadyProduct $ready, SaveReadyProduct $save): RedirectResponse
    {
        $save->handle($this->actor($request), $ready, $request->catalogData());
        $this->toast(__('catalog.ready.updated'));

        return to_route('catalog.ready.edit', $ready);
    }

    public function status(Request $request, CatalogReadyProduct $ready, SetCatalogStatus $status): RedirectResponse
    {
        $to = CatalogStatus::tryFrom((string) $request->input('status'));
        abort_unless($to !== null, 422);

        $status->handle($this->actor($request), $ready, $to);
        $this->toast(__('catalog.ready.status_'.$to->value, ['name' => $ready->name->get()]));

        return to_route('catalog.ready.edit', $ready);
    }

    public function storeVariant(VariantRequest $request, CatalogReadyProduct $ready, SaveVariant $save): RedirectResponse
    {
        $variant = $save->handle($this->actor($request), $ready, null, $request->catalogData());
        $this->toast(__('catalog.variant.created', ['code' => $variant->code]));

        return to_route('catalog.ready.edit', $ready);
    }

    public function updateVariant(VariantRequest $request, CatalogReadyProduct $ready, CatalogReadyVariant $variant, SaveVariant $save): RedirectResponse
    {
        $save->handle($this->actor($request), $ready, $variant, $request->catalogData());
        $this->toast(__('catalog.variant.updated'));

        return to_route('catalog.ready.edit', $ready);
    }

    public function variantStatus(Request $request, CatalogReadyProduct $ready, CatalogReadyVariant $variant, SetCatalogStatus $status): RedirectResponse
    {
        abort_unless($variant->ready_product_id === $ready->id, 404);
        $to = CatalogStatus::tryFrom((string) $request->input('status'));
        abort_unless($to !== null, 422);

        $status->handle($this->actor($request), $variant, $to);
        $this->toast(__('catalog.variant.status_'.$to->value, ['code' => $variant->code]));

        return to_route('catalog.ready.edit', $ready);
    }
}
