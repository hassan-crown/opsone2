<?php

namespace App\Http\Controllers\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Orders\CreateOrders;
use App\Actions\Orders\UpdateOrder;
use App\Customers\CustomerPresenter;
use App\Enums\CustomOrderStatus;
use App\Enums\OrderKind;
use App\Enums\ReadyOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\CreateOrderRequest;
use App\Http\Requests\Orders\UpdateOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Orders\OrderPresenter;
use App\Organization\OrgPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Orders backend for the P4 order screens (COM-08..11). JSON endpoints under the web session (CSRF, auth); the
 * screens session wraps them in Inertia pages. Every endpoint authorizes on the record; lists use ScopedQuery.
 * Contract: docs/p4-orders-api.md.
 */
class OrderController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
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

        return response()->json([
            'orders' => array_map(fn (Order $o) => OrderPresenter::row($o, $actor), $page->items()),
            'meta' => CustomerPresenter::meta($page),
        ]);
    }

    /**
     * What the create screen may offer: branches per kind (P-15, `ready_goods`) and members.
     */
    public function options(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P15), 403);

        $branches = fn (OrderKind $kind) => array_map(
            fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)],
            OrderAccess::creatableBranches($actor, $kind),
        );

        return response()->json([
            'branches' => ['custom' => $branches(OrderKind::Custom), 'ready' => $branches(OrderKind::Ready)],
            'members' => CustomerPresenter::memberOptions(),
            'invoice_types' => Order::INVOICE_TYPES,
        ]);
    }

    public function store(CreateOrderRequest $request, CreateOrders $create): JsonResponse
    {
        $actor = $this->actor($request);
        ['result' => $result, 'replayed' => $replayed] = $create->handle($actor, (string) $request->validated('idempotency_key'), $request->orderData());

        $orders = Order::query()->whereIn('id', $result['order_ids'])->orderBy('id')->get()
            ->map(fn (Order $o) => OrderPresenter::detail($o, $actor))->all();

        return response()->json([
            'orders' => $orders,
            'purchase_reference_id' => $result['purchase_reference_id'],
            'replayed' => $replayed,
        ], $replayed ? 200 : 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(OrderAccess::canView($actor, $order), 403);

        return response()->json(['order' => OrderPresenter::detail($order, $actor)]);
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
