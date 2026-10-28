<?php

namespace App\Http\Controllers\Catalog;

use App\Access\Permission;
use App\Actions\Catalog\SaveMaterial;
use App\Actions\Catalog\SetCatalogStatus;
use App\Catalog\Availability;
use App\Catalog\CatalogStatus;
use App\Http\Requests\Catalog\MaterialRequest;
use App\Models\Catalog\CatalogMaterial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Made-to-order materials and models (COM-05, P-13): descriptive items reused across products. Quantity tracking
 * is only a flag here; balances belong to the stock domain.
 */
final class MaterialController extends CatalogController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P13);

        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'disabled'], true) ? (string) $request->query('status') : null;
        $group = $request->filled('group') ? (string) $request->query('group') : null;
        $availability = Availability::tryFrom((string) $request->query('availability'))?->value;

        $materials = CatalogMaterial::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($group, fn ($query) => $query->where('material_group', $group))
            ->when($availability, fn ($query) => $query->where('availability', $availability))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', $this->like($q))->orWhere('supplier_name', 'like', $this->like($q))))
            ->orderBy('status')->orderBy('material_group')->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (CatalogMaterial $m) => $this->presenter->material($m, $actor));

        return Inertia::render('catalog/materials', [
            'materials' => $materials,
            'filters' => ['q' => $q, 'status' => $status, 'group' => $group, 'availability' => $availability],
            'groups' => CatalogMaterial::query()->whereNotNull('material_group')->distinct()->orderBy('material_group')->pluck('material_group')->all(),
            'tabs' => $this->tabs($actor),
            'canCreate' => $this->access->can($actor, Permission::P13, new CatalogMaterial),
        ]);
    }

    public function store(MaterialRequest $request, SaveMaterial $save): RedirectResponse
    {
        $material = $save->handle($this->actor($request), null, $request->catalogData());
        $this->toast(__('catalog.material.created', ['name' => $material->name->get()]));

        return to_route('catalog.materials.index');
    }

    public function update(MaterialRequest $request, CatalogMaterial $material, SaveMaterial $save): RedirectResponse
    {
        $save->handle($this->actor($request), $material, $request->catalogData());
        $this->toast(__('catalog.material.updated'));

        return back();
    }

    public function disable(Request $request, CatalogMaterial $material, SetCatalogStatus $status): RedirectResponse
    {
        $status->handle($this->actor($request), $material, CatalogStatus::Disabled);
        $this->toast(__('catalog.material.disabled', ['name' => $material->name->get()]));

        return back();
    }

    public function enable(Request $request, CatalogMaterial $material, SetCatalogStatus $status): RedirectResponse
    {
        $status->handle($this->actor($request), $material, CatalogStatus::Active);
        $this->toast(__('catalog.material.enabled', ['name' => $material->name->get()]));

        return back();
    }
}
