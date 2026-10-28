<?php

namespace App\Http\Controllers\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Procurement\SaveSupplier;
use App\Actions\Procurement\SetSupplierStatus;
use App\Http\Requests\Procurement\EnvelopeRequest;
use App\Http\Requests\Procurement\SupplierRequest;
use App\Models\Procurement\Supplier;
use App\Procurement\PurchaseAccess;
use App\Procurement\PurchasePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier profiles (FUL-05, P-45): SupplierList and SupplierNew. Whoever buys or receives may read the list.
 */
final class SupplierController extends ProcurementController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(PurchaseAccess::canListSuppliers($actor), 403);

        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), [Supplier::ACTIVE, Supplier::DISABLED], true) ? (string) $request->query('status') : null;

        $suppliers = Supplier::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', $this->like($q))->orWhere('contact_name', 'like', $this->like($q))->orWhere('vat_number', 'like', $this->like($q))))
            ->orderBy('status')->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (Supplier $s) => PurchasePresenter::supplier($s));

        return Inertia::render('suppliers/index', [
            'suppliers' => $suppliers,
            'filters' => ['q' => $q, 'status' => $status],
            'canManage' => Access::can($actor, Permission::P45),
            'canPurchase' => Access::can($actor, Permission::P46) || Access::can($actor, Permission::P47),
        ]);
    }

    public function create(Request $request): Response
    {
        Access::authorize($this->actor($request), Permission::P45, new Supplier);

        return Inertia::render('suppliers/form', ['supplier' => null]);
    }

    public function store(SupplierRequest $request, SaveSupplier $save): RedirectResponse
    {
        $supplier = $save->handle($this->actor($request), null, $request->supplierData(), null, $request->key());
        $this->toast(__('suppliers.created', ['name' => $supplier->name->get()]));

        return to_route('suppliers.index');
    }

    public function edit(Request $request, Supplier $supplier): Response
    {
        Access::authorize($this->actor($request), Permission::P45, $supplier);

        return Inertia::render('suppliers/form', ['supplier' => PurchasePresenter::supplier($supplier)]);
    }

    public function update(SupplierRequest $request, Supplier $supplier, SaveSupplier $save): RedirectResponse
    {
        $save->handle($this->actor($request), $supplier, $request->supplierData(), $request->lockVersion(), $request->key());
        $this->toast(__('suppliers.updated'));

        return to_route('suppliers.index');
    }

    public function disable(EnvelopeRequest $request, Supplier $supplier, SetSupplierStatus $status): RedirectResponse
    {
        $status->handle($this->actor($request), $supplier, false, $request->lockVersion());
        $this->toast(__('suppliers.disabled', ['name' => $supplier->name->get()]));

        return back();
    }

    public function enable(EnvelopeRequest $request, Supplier $supplier, SetSupplierStatus $status): RedirectResponse
    {
        $status->handle($this->actor($request), $supplier, true, $request->lockVersion());
        $this->toast(__('suppliers.enabled', ['name' => $supplier->name->get()]));

        return back();
    }
}
