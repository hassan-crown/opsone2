<?php

namespace App\Http\Controllers\Customers;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Actions\Customers\UpdateInterest;
use App\Actions\StateMachines\M03Interest\T00CreateInterest;
use App\Actions\StateMachines\M03Interest\T01TakeFollowUp;
use App\Actions\StateMachines\M03Interest\T02ConvertInterest;
use App\Actions\StateMachines\M03Interest\T03CloseInterest;
use App\Actions\StateMachines\M03Interest\T04ReopenInterest;
use App\Customers\CustomerAccess;
use App\Customers\CustomerPresenter;
use App\Enums\InterestChannel;
use App\Enums\InterestCloseReason;
use App\Enums\InterestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\InterestCloseRequest;
use App\Http\Requests\Customers\InterestReopenRequest;
use App\Http\Requests\Customers\InterestRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Organization\OrgPresenter;
use App\Search\FulltextMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Interests (COM-03, M-03): scoped list with status tabs and filters, detail, record/edit, follow, convert, close, reopen.
 */
class InterestController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P09) || Access::can($actor, Permission::P15), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', InterestStatus::values())],
            'channel' => ['nullable', 'in:'.implode(',', InterestChannel::values())],
            'assignee' => ['nullable', 'string', 'max:20'],
            'branch' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $counts = CustomerAccess::visibleInterests($actor)->toBase()
            ->selectRaw('interests.status as status, count(*) as total')->groupBy('interests.status')->pluck('total', 'status');

        $query = CustomerAccess::visibleInterests($actor)->with(['branch', 'assignee:id,name'])->orderByDesc('interests.id');
        if (filled($filters['q'] ?? null)) {
            FulltextMatch::apply($query, ['interests.name', 'interests.need', 'interests.reference', 'interests.mobile'], $filters['q'], [['name', 'need']]);
        }
        foreach (['status', 'channel'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where('interests.'.$column, $filters[$column]);
            }
        }
        if (filled($filters['assignee'] ?? null)) {
            $filters['assignee'] === 'none'
                ? $query->whereNull('interests.assignee_id')
                : $query->where('interests.assignee_id', (int) $filters['assignee']);
        }
        if (filled($filters['branch'] ?? null)) {
            $query->where('interests.branch_id', (int) $filters['branch']);
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('interests/index', [
            'interests' => array_map(fn (Interest $i) => CustomerPresenter::interest($i) + ['can' => $this->can($actor, $i)], $page->items()),
            'meta' => CustomerPresenter::meta($page),
            'counts' => collect(InterestStatus::values())->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'filters' => [
                'q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'channel' => $filters['channel'] ?? '',
                'assignee' => $filters['assignee'] ?? '', 'branch' => isset($filters['branch']) ? (string) $filters['branch'] : '',
            ],
            'members' => CustomerPresenter::memberOptions(),
            'branches' => Branch::query()->orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all(),
            'canCreate' => CustomerAccess::creatableBranches($actor) !== [],
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P09), 403);

        $existing = $request->integer('customer_id') > 0 ? Customer::query()->find($request->integer('customer_id')) : null;
        $existing = $existing !== null && CustomerAccess::canViewCustomer($actor, $existing) ? $existing : null;

        return Inertia::render('interests/form', [
            'interest' => null,
            'prefill' => $existing === null ? null : [
                'customer_id' => $existing->id, 'name' => $existing->name, 'mobile' => $existing->mobile,
                'email' => $existing->email, 'branch_id' => $existing->branch_id,
            ],
            'branches' => CustomerPresenter::branchOptions($actor),
            'members' => CustomerPresenter::memberOptions(),
        ]);
    }

    public function store(InterestRequest $request, T00CreateInterest $create): RedirectResponse
    {
        $interest = $create->handle($this->actor($request), $request->validated());
        $this->toast(__('interests.created', ['reference' => $interest->reference]));

        return to_route('interests.show', $interest);
    }

    public function show(Request $request, Interest $interest): Response
    {
        $actor = $this->actor($request);
        abort_unless(CustomerAccess::canViewInterest($actor, $interest), 403);
        $interest->load(['branch', 'assignee:id,name']);

        $customer = $interest->converted_customer_id ?? $interest->customer_id;
        $customer = $customer === null ? null : Customer::query()->find($customer);

        $order = $interest->converted_order_id === null ? null : Order::query()->find($interest->converted_order_id);

        return Inertia::render('interests/show', [
            'interest' => CustomerPresenter::interest($interest),
            'customer' => $customer !== null && CustomerAccess::canViewCustomer($actor, $customer)
                ? ['id' => $customer->id, 'name' => $customer->name] : null,
            'order' => $order !== null && OrderAccess::canView($actor, $order) ? ['id' => $order->id, 'number' => $order->number] : null,
            'can' => $this->can($actor, $interest),
            'members' => CustomerPresenter::memberOptions(),
        ]);
    }

    public function edit(Request $request, Interest $interest): Response
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P09, $interest);
        abort_unless($interest->isOpen(), 409);

        return Inertia::render('interests/form', [
            'interest' => CustomerPresenter::interest($interest->load(['branch', 'assignee:id,name'])),
            'prefill' => null,
            'branches' => [],
            'members' => CustomerPresenter::memberOptions(),
        ]);
    }

    public function update(InterestRequest $request, Interest $interest, UpdateInterest $update): RedirectResponse
    {
        $update->handle($this->actor($request), $interest, $request->validated());
        $this->toast(__('interests.updated'));

        return to_route('interests.show', $interest);
    }

    public function follow(Request $request, Interest $interest, T01TakeFollowUp $follow): RedirectResponse
    {
        $follow->handle($this->actor($request), $interest);
        $this->toast(__('interests.followed'));

        return back();
    }

    public function convert(Request $request, Interest $interest, T02ConvertInterest $convert): RedirectResponse
    {
        $actor = $this->actor($request);

        try {
            $converted = $convert->handle($actor, $interest);
        } catch (InvalidTransition $e) {
            // Idempotent: a repeated or concurrent conversion opens what the first one made instead of failing.
            if ($e->condition === 'already_converted') {
                $this->toast(__('interests.already_converted'), 'info');

                return to_route('interests.show', $interest);
            }
            throw $e;
        }

        $this->toast(__('interests.converted'));

        return to_route('customers.show', $converted->converted_customer_id);
    }

    public function close(InterestCloseRequest $request, Interest $interest, T03CloseInterest $close): RedirectResponse
    {
        $close->handle($this->actor($request), $interest, InterestCloseReason::from((string) $request->validated('reason')), $request->validated('note'));
        $this->toast(__('interests.closed'));

        return to_route('interests.show', $interest);
    }

    public function reopen(InterestReopenRequest $request, Interest $interest, T04ReopenInterest $reopen): RedirectResponse
    {
        $assignee = $request->validated('assignee_id');
        $reopen->handle($this->actor($request), $interest, (string) $request->validated('reason'), $assignee === null ? null : (int) $assignee);
        $this->toast(__('interests.reopened'));

        return to_route('interests.show', $interest);
    }

    /**
     * What the actor may do with the interest, for showing buttons; every action is re-checked server-side.
     *
     * @return array{edit: bool, follow: bool, convert: bool, close: bool, reopen: bool}
     */
    private function can(User $actor, Interest $interest): array
    {
        $manage = Access::can($actor, Permission::P09, $interest);

        return [
            'edit' => $manage && $interest->isOpen(),
            'follow' => $manage && $interest->status === InterestStatus::New,
            'convert' => $interest->isOpen() && Access::can($actor, Permission::P15, $interest),
            'close' => $manage && $interest->isOpen(),
            'reopen' => $manage && $interest->status === InterestStatus::Closed,
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
