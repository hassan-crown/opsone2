<?php

namespace App\Http\Controllers\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Orders\CreateOrders;
use App\Actions\Orders\UpdateOrder;
use App\Customers\CustomerAccess;
use App\Customers\CustomerPresenter;
use App\Enums\CustomOrderStatus;
use App\Enums\OrderKind;
use App\Enums\ReadyOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\CreateOrderRequest;
use App\Http\Requests\Orders\UpdateOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\StateTransition;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Orders\OrderPicker;
use App\Orders\OrderPresenter;
use App\Organization\OrgPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Orders backend for the P4 order screens (COM-08..11). JSON endpoints under the web session (CSRF, auth); the
 * screens session wraps them in Inertia pages. Every endpoint authorizes on the record; lists use ScopedQuery.
 * Contract: docs/p4-orders-api.md.
 */
class OrderController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): JsonResponse|Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P16), 403);

        $statuses = [...array_column(CustomOrderStatus::cases(), 'value'), ...array_column(ReadyOrderStatus::cases(), 'value')];
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(OrderKind::values())],
            'status' => ['nullable', Rule::in(array_values(array_unique($statuses)))],
            'branch' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'max:20'],
            'customer' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = OrderAccess::visible($actor)
            ->with(['customer:id,name', 'branch', 'assignee:id,name', 'purchaseReference:id,number'])
            ->orderByDesc('orders.id');

        if (filled($filters['q'] ?? null)) {
            $q = str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']);
            $query->where(fn ($w) => $w->where('orders.number', 'like', $q.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$q.'%')));
        }
        foreach (['kind' => 'orders.kind', 'status' => 'orders.status'] as $key => $column) {
            if (filled($filters[$key] ?? null)) {
                $query->where($column, $filters[$key]);
            }
        }
        if (filled($filters['branch'] ?? null)) {
            $query->where('orders.branch_id', (int) $filters['branch']);
        }
        if (filled($filters['customer'] ?? null)) {
            $query->where('orders.customer_id', (int) $filters['customer']);
        }
        if (filled($filters['assignee'] ?? null)) {
            $filters['assignee'] === 'none'
                ? $query->whereNull('orders.assignee_id')
                : $query->where('orders.assignee_id', (int) $filters['assignee']);
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        $rows = array_map(fn (Order $o) => OrderPresenter::row($o, $actor), $page->items());
        $meta = CustomerPresenter::meta($page);

        if ($this->wantsJson($request)) {
            return response()->json(['orders' => $rows, 'meta' => $meta]);
        }

        return Inertia::render('orders/index', [
            'orders' => $rows,
            'meta' => $meta,
            'filters' => [
                'q' => $filters['q'] ?? '', 'kind' => $filters['kind'] ?? '', 'status' => $filters['status'] ?? '',
                'branch' => isset($filters['branch']) ? (string) $filters['branch'] : '',
                'assignee' => $filters['assignee'] ?? '',
            ],
            'members' => CustomerPresenter::memberOptions(),
            'branches' => array_values(Branch::query()->orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all()),
            'statuses' => array_values(array_unique($statuses)),
            'canCreate' => Access::can($actor, Permission::P15),
        ]);
    }

    /**
     * The create screen. Branch choices per kind and the ready section follow the Gate and the license.
     */
    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P15), 403);

        $options = $this->optionsFor($actor);
        abort_unless($options['branches']['custom'] !== [] || $options['branches']['ready'] !== [], 403);

        return Inertia::render('orders/create', [
            'options' => $options,
            'preselectedCustomer' => $this->preselectedCustomer($request, $actor),
        ]);
    }

    /**
     * Customer search for the picker: only customers the actor may see.
     */
    public function customers(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P15), 403);

        return response()->json(['customers' => OrderPicker::customers($actor, (string) $request->query('q', ''))]);
    }

    /**
     * Selectable catalog items with the version a new line would freeze; money only with P-37 for the branch.
     */
    public function catalog(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P15), 403);
        $data = $request->validate([
            'kind' => ['required', Rule::in(OrderKind::values())],
            'branch_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $kind = OrderKind::from($data['kind']);
        abort_unless(collect(OrderAccess::creatableBranches($actor, $kind))->contains('id', (int) $data['branch_id']), 403);

        return response()->json(['items' => OrderPicker::catalog($actor, $kind, (int) $data['branch_id'], (string) ($data['q'] ?? ''))]);
    }

    /**
     * What the create screen may offer: branches per kind (P-15, `ready_goods`) and members.
     */
    public function options(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P15), 403);

        return response()->json($this->optionsFor($actor));
    }

    /**
     * @return array{branches: array{custom: list<array{id: int, name: string}>, ready: list<array{id: int, name: string}>}, members: list<array{id: int, name: string}>, invoice_types: list<string>}
     */
    private function optionsFor(User $actor): array
    {
        $branches = fn (OrderKind $kind) => array_map(
            fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)],
            OrderAccess::creatableBranches($actor, $kind),
        );

        return [
            'branches' => ['custom' => $branches(OrderKind::Custom), 'ready' => $branches(OrderKind::Ready)],
            'members' => CustomerPresenter::memberOptions(),
            'invoice_types' => Order::INVOICE_TYPES,
        ];
    }

    /**
     * `?customer=` from the customer profile pre-fills the picker when the actor may see that customer.
     *
     * @return array<string, mixed>|null
     */
    private function preselectedCustomer(Request $request, User $actor): ?array
    {
        $id = (int) $request->query('customer', 0);
        if ($id < 1) {
            return null;
        }
        $found = CustomerAccess::visibleCustomers($actor)->with('branch')->whereKey($id)->first();

        return $found === null ? null : OrderPicker::customerRow($found);
    }

    public function store(CreateOrderRequest $request, CreateOrders $create): JsonResponse
    {
        $actor = $this->actor($request);
        ['result' => $result, 'replayed' => $replayed] = $create->handle($actor, (string) $request->validated('idempotency_key'), $request->orderData());

        // A replay may come after a transfer or a grant change: only orders the actor still sees come back.
        $orders = Order::query()->whereIn('id', $result['order_ids'])->orderBy('id')->get()
            ->filter(fn (Order $o) => OrderAccess::canView($actor, $o))
            ->map(fn (Order $o) => OrderPresenter::detail($o, $actor))->values()->all();

        return response()->json([
            'orders' => $orders,
            'purchase_reference_id' => $result['purchase_reference_id'],
            'replayed' => $replayed,
        ], $replayed ? 200 : 201);
    }

    public function show(Request $request, Order $order): JsonResponse|Response
    {
        $actor = $this->actor($request);
        abort_unless(OrderAccess::canView($actor, $order), 403);
        $detail = OrderPresenter::detail($order, $actor);

        if ($this->wantsJson($request)) {
            return response()->json(['order' => $detail]);
        }

        return Inertia::render('orders/show', [
            'order' => $detail,
            'history' => $this->history($order),
            'members' => CustomerPresenter::memberOptions(),
            'invoiceTypes' => Order::INVOICE_TYPES,
            // Targets for the transfer dialog; the server still checks P-19 over both branches on preview and apply.
            'transferBranches' => $detail['can']['transfer_branch']
                ? array_values(Branch::query()->where('status', 'active')->whereKeyNot($order->branch_id)->orderBy('id')->get()
                    ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all())
                : [],
        ]);
    }

    /**
     * State history of the order (M-04/M-05) from the append-only transition log, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function history(Order $order): array
    {
        $names = [];

        return array_values(StateTransition::query()
            ->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()
            ->map(function (StateTransition $t) use (&$names) {
                if ($t->actor_user_id !== null && ! isset($names[$t->actor_user_id])) {
                    $names[$t->actor_user_id] = (string) User::query()->whereKey($t->actor_user_id)->value('name');
                }

                return [
                    'id' => $t->id,
                    'transition' => $t->transition,
                    'from' => $t->from_state,
                    'to' => $t->to_state,
                    'actor' => $t->actor_user_id === null ? null : $names[$t->actor_user_id],
                    'reason' => $t->reason,
                    'at' => $t->occurred_at->toIso8601String(),
                ];
            })->all());
    }

    /**
     * Pages load through Inertia; scripts and tests ask for JSON (`Accept: application/json`) and get the contract.
     */
    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }

    public function update(UpdateOrderRequest $request, Order $order, UpdateOrder $update): JsonResponse
    {
        $actor = $this->actor($request);
        $order = $update->handle($actor, $order, (int) $request->validated('lock_version'), $request->changes());

        return response()->json(['order' => OrderPresenter::detail($order->refresh(), $actor)]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
