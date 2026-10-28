<?php

namespace App\Http\Controllers\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Procurement\UpdateDraftPurchase;
use App\Actions\StateMachines\M13PurchaseOrder\T00CreatePurchaseOrder;
use App\Actions\StateMachines\M13PurchaseOrder\T01ConfirmPurchaseOrder;
use App\Actions\StateMachines\M13PurchaseOrder\T02ReceivePurchase;
use App\Actions\StateMachines\M13PurchaseOrder\T03CancelRemainder;
use App\Actions\StateMachines\M13PurchaseOrder\T04AdjustQuantities;
use App\Customers\CustomerPresenter;
use App\Enums\PurchaseStatus;
use App\Http\Requests\Procurement\AdjustRequest;
use App\Http\Requests\Procurement\EnvelopeRequest;
use App\Http\Requests\Procurement\PurchaseRequest;
use App\Http\Requests\Procurement\ReasonRequest;
use App\Http\Requests\Procurement\ReceiveRequest;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Procurement\Supplier;
use App\Models\User;
use App\Procurement\Locations;
use App\Procurement\PurchaseAccess;
use App\Procurement\PurchasePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase orders (M-13, FUL-05..07): PurchaseList, PurchaseNew, the order page, PurchaseReceive and PoCancel. The
 * writes are Actions; this class only reads, validates the envelope and answers.
 */
final class PurchaseController extends ProcurementController
{
    public function __construct(private readonly PurchasePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P46) || Access::can($actor, Permission::P47), 403);

        $q = trim((string) $request->query('q', ''));
        $status = PurchaseStatus::tryFrom((string) $request->query('status'))?->value;
        $supplier = $request->filled('supplier') ? (int) $request->query('supplier') : null;

        $orders = PurchaseAccess::scoped(PurchaseOrder::query(), $actor)
            ->with(['supplier', 'branch', 'lines'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($supplier, fn ($query) => $query->where('supplier_id', $supplier))
            ->when($q !== '', fn ($query) => $query->where('number', 'like', $this->like($q)))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (PurchaseOrder $po) => PurchasePresenter::row($po, $actor));

        return Inertia::render('purchases/index', [
            'orders' => $orders,
            'filters' => ['q' => $q, 'status' => $status, 'supplier' => $supplier],
            'suppliers' => $this->supplierOptions(),
            'canCreate' => PurchasePresenter::creatableBranches($actor) !== [],
            'canManageSuppliers' => Access::can($actor, Permission::P45),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $branches = PurchasePresenter::creatableBranches($actor);
        abort_unless($branches !== [], 403);

        return Inertia::render('purchases/form', [...$this->formOptions($actor, $branches), 'purchase' => null, 'prefill' => $this->prefill($request)]);
    }

    public function store(PurchaseRequest $request, T00CreatePurchaseOrder $create): RedirectResponse
    {
        $po = $create->handle($this->actor($request), $request->purchaseData(), $request->key());
        $this->toast(__('procurement.created', ['number' => $po->number]));

        return to_route('purchases.show', $po);
    }

    public function show(Request $request, PurchaseOrder $purchase): Response
    {
        $actor = $this->actor($request);
        abort_unless(PurchaseAccess::canView($actor, $purchase), 403);

        return Inertia::render('purchases/show', ['purchase' => $this->presenter->detail($purchase, $actor)]);
    }

    public function edit(Request $request, PurchaseOrder $purchase): Response
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P46, $purchase);
        abort_unless($purchase->status === PurchaseStatus::Draft, 409);

        return Inertia::render('purchases/form', [
            ...$this->formOptions($actor, PurchasePresenter::creatableBranches($actor)),
            'purchase' => $this->presenter->detail($purchase, $actor),
            'prefill' => null,
        ]);
    }

    public function update(PurchaseRequest $request, PurchaseOrder $purchase, UpdateDraftPurchase $update): RedirectResponse
    {
        $update->handle($this->actor($request), $purchase, $request->purchaseData(), $request->lockVersion(), $request->key());
        $this->toast(__('procurement.updated'));

        return to_route('purchases.show', $purchase);
    }

    public function confirm(EnvelopeRequest $request, PurchaseOrder $purchase, T01ConfirmPurchaseOrder $confirm): RedirectResponse
    {
        $confirm->handle($this->actor($request), $purchase, $request->lockVersion(), $request->key());
        $this->toast(__('procurement.confirmed', ['number' => $purchase->number]));

        return to_route('purchases.show', $purchase);
    }

    public function receiveScreen(Request $request, PurchaseOrder $purchase): Response
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P47, $purchase);
        abort_unless($purchase->status->open(), 409);

        return Inertia::render('purchases/receive', ['purchase' => $this->presenter->detail($purchase, $actor), 'locations' => Locations::options()]);
    }

    public function receive(ReceiveRequest $request, PurchaseOrder $purchase, T02ReceivePurchase $receive): RedirectResponse
    {
        $receive->handle($this->actor($request), $purchase, $request->receiptData(), $request->proofs(), $request->lockVersion(), $request->key());
        $this->toast(__('procurement.received'));

        return to_route('purchases.show', $purchase);
    }

    public function cancelScreen(Request $request, PurchaseOrder $purchase): Response
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P46, $purchase);
        abort_unless(in_array($purchase->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered, PurchaseStatus::PartiallyReceived], true), 409);

        return Inertia::render('purchases/cancel', ['purchase' => $this->presenter->detail($purchase, $actor)]);
    }

    public function cancel(ReasonRequest $request, PurchaseOrder $purchase, T03CancelRemainder $cancel): RedirectResponse
    {
        $cancel->handle($this->actor($request), $purchase, (string) $request->validated('reason'), $request->lockVersion(), $request->key());
        $this->toast(__('procurement.cancelled', ['number' => $purchase->number]));

        return to_route('purchases.show', $purchase);
    }

    public function adjust(AdjustRequest $request, PurchaseOrder $purchase, T04AdjustQuantities $adjust): RedirectResponse
    {
        $adjust->handle($this->actor($request), $purchase, $request->changes(), (string) $request->validated('reason'), $request->lockVersion(), $request->key());
        $this->toast(__('procurement.adjusted'));

        return to_route('purchases.show', $purchase);
    }

    /**
     * Items a line can be added for: made-to-order materials and ready variants that are not disabled.
     */
    public function items(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P46), 403);

        $q = trim((string) $request->query('q', ''));
        $like = $this->like($q);

        $materials = CatalogMaterial::query()->selectable()
            ->when($q !== '', fn ($query) => $query->where('name', 'like', $like))
            ->orderBy('id')->limit(15)->get()
            ->map(fn (CatalogMaterial $m) => [
                'item_type' => PurchaseOrderLine::MATERIAL, 'item_id' => $m->id, 'name' => PurchasePresenter::text($m->name),
                'unit' => $m->unit, 'tracked' => $m->track_quantity, 'code' => null,
            ]);
        $variants = CatalogReadyVariant::query()->with('product')->whereNot('status', 'disabled')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)))
            ->orderBy('id')->limit(15)->get()
            ->map(fn (CatalogReadyVariant $v) => [
                'item_type' => PurchaseOrderLine::READY_VARIANT, 'item_id' => $v->id,
                'name' => ['ar' => $v->product->name->only('ar').' — '.$v->name->only('ar'), 'en' => $v->product->name->only('en').' — '.$v->name->only('en'),
                    'label' => $v->product->name->get().' — '.$v->name->get()],
                'unit' => 'piece', 'tracked' => true, 'code' => $v->code,
            ]);

        return response()->json(['items' => $materials->concat($variants)->values()->all()]);
    }

    /**
     * @return list<array{id: int, name: array{ar: string|null, en: string|null, label: string}}>
     */
    private function supplierOptions(bool $activeOnly = false): array
    {
        return array_values(Supplier::query()->when($activeOnly, fn ($q) => $q->where('status', Supplier::ACTIVE))->orderBy('id')->get()
            ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => PurchasePresenter::text($s->name)])->all());
    }

    /**
     * @param  list<array{id: int, name: string}>  $branches
     * @return array<string, mixed>
     */
    private function formOptions(User $actor, array $branches): array
    {
        return [
            'suppliers' => $this->supplierOptions(true),
            'branches' => $branches,
            'members' => CustomerPresenter::memberOptions(),
            'locations' => Locations::options(),
            'canCost' => Access::can($actor, Permission::P82),
        ];
    }

    /**
     * `?item_type=&item_id=&quantity=` (from a shortage) pre-fills one line.
     *
     * @return array<string, mixed>|null
     */
    private function prefill(Request $request): ?array
    {
        $type = (string) $request->query('item_type');
        $id = (int) $request->query('item_id');
        if ($id < 1 || ! in_array($type, [PurchaseOrderLine::MATERIAL, PurchaseOrderLine::READY_VARIANT], true) || ! PurchaseOrderLine::itemExists($type, $id)) {
            return null;
        }

        return ['item_type' => $type, 'item_id' => $id, 'quantity' => (string) $request->query('quantity', '')];
    }
}
