<?php

namespace App\Http\Controllers\Customers;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Customers\CustomerAccess;
use App\Customers\CustomerPresenter;
use App\Enums\CustomerKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Search\FulltextMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers (COM-01/02, P-09, P-10): scoped list with filters, profile, create and edit.
 */
class CustomerController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P09) || Access::can($actor, Permission::P10), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', 'in:'.implode(',', CustomerKind::values())],
            'assignee' => ['nullable', 'string', 'max:20'],
            'branch' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = CustomerAccess::visibleCustomers($actor)->with(['branch', 'assignee:id,name'])->orderByDesc('customers.id');

        if (filled($filters['q'] ?? null)) {
            FulltextMatch::apply($query, ['customers.name', 'customers.mobile', 'customers.email'], $filters['q'], [['name', 'mobile', 'email']]);
        }
        if (filled($filters['kind'] ?? null)) {
            $query->where('customers.kind', $filters['kind']);
        }
        if (filled($filters['assignee'] ?? null)) {
            $filters['assignee'] === 'none'
                ? $query->whereNull('customers.assignee_id')
                : $query->where('customers.assignee_id', (int) $filters['assignee']);
        }
        if (filled($filters['branch'] ?? null)) {
            $query->where('customers.branch_id', (int) $filters['branch']);
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('customers/index', [
            'customers' => array_map(fn (Customer $c) => CustomerPresenter::row($c, $actor), $page->items()),
            'meta' => CustomerPresenter::meta($page),
            'filters' => [
                'q' => $filters['q'] ?? '', 'kind' => $filters['kind'] ?? '',
                'assignee' => $filters['assignee'] ?? '', 'branch' => isset($filters['branch']) ? (string) $filters['branch'] : '',
            ],
            'members' => CustomerPresenter::memberOptions(),
            'branches' => $this->allBranches(),
            'canCreate' => CustomerAccess::creatableBranches($actor) !== [],
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P09), 403);

        return Inertia::render('customers/form', [
            'customer' => null,
            'branches' => CustomerPresenter::branchOptions($actor),
            'members' => CustomerPresenter::memberOptions(),
        ]);
    }

    public function store(CustomerRequest $request, CreateCustomer $create): RedirectResponse
    {
        $customer = $create->handle($this->actor($request), $request->customerData());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('customers.created', ['name' => $customer->name])]);

        return to_route('customers.show', $customer);
    }

    public function show(Request $request, Customer $customer): Response
    {
        $actor = $this->actor($request);
        abort_unless(CustomerAccess::canViewCustomer($actor, $customer), 403);
        $customer->load(['branch', 'assignee:id,name']);

        $interests = CustomerAccess::visibleInterests($actor)
            ->where(fn ($q) => $q->where('interests.customer_id', $customer->id)->orWhere('interests.converted_customer_id', $customer->id))
            ->with(['branch', 'assignee:id,name'])->orderByDesc('interests.id')->limit(10)->get();

        return Inertia::render('customers/show', [
            'customer' => CustomerPresenter::detail($customer, $actor),
            'sensitiveHidden' => ! CustomerAccess::canSeeSensitive($actor, $customer),
            'contacts' => $customer->isOrganization()
                ? $customer->contacts()->whereNull('removed_at')->orderByDesc('is_primary')->orderBy('id')->get()->map(CustomerPresenter::contact(...))->all()
                : [],
            'interests' => $interests->map(fn (Interest $i) => CustomerPresenter::interest($i))->all(),
            'can' => ['edit' => Access::can($actor, Permission::P10, $customer)],
        ]);
    }

    public function edit(Request $request, Customer $customer): Response
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P10, $customer);

        return Inertia::render('customers/form', [
            'customer' => CustomerPresenter::detail($customer->load(['branch', 'assignee:id,name']), $actor),
            'branches' => [],
            'members' => CustomerPresenter::memberOptions(),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer, UpdateCustomer $update): RedirectResponse
    {
        $update->handle($this->actor($request), $customer, $request->customerData());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('customers.updated')]);

        return to_route('customers.show', $customer);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * Branch names for the filter: only branches where the user can see at least one customer matter, but names of
     * branches are not secret inside the facility (P-08-like), so the full active list is offered.
     *
     * @return list<array{id: int, name: string}>
     */
    private function allBranches(): array
    {
        return array_values(Branch::query()->orderBy('id')->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all());
    }
}
