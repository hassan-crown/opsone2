<?php

namespace App\Http\Controllers\Returns;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Returns\RequestReturnRefund;
use App\Actions\Returns\UpdateReturnSettings;
use App\Actions\StateMachines\M15Return\T00RequestReturn;
use App\Actions\StateMachines\M15Return\T01ReceiveReturn;
use App\Actions\StateMachines\M15Return\T02InspectReturn;
use App\Actions\StateMachines\M15Return\T03WithdrawReturn;
use App\Actions\StateMachines\M15Return\T04CorrectInspection;
use App\Actions\StateMachines\M15Return\T05AcceptReturn;
use App\Actions\StateMachines\M15Return\T06RejectReturn;
use App\Enums\OrderKind;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Returns\AcceptReturnRequest;
use App\Http\Requests\Returns\CorrectReturnRequest;
use App\Http\Requests\Returns\InspectReturnRequest;
use App\Http\Requests\Returns\ReceiveReturnRequest;
use App\Http\Requests\Returns\ReturnReasonRequest;
use App\Http\Requests\Returns\ReturnRefundRequest;
use App\Http\Requests\Returns\ReturnSettingsRequest;
use App\Http\Requests\Returns\StoreReturnRequest;
use App\Models\Order;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\ReturnSettings;
use App\Models\User;
use App\Returns\ReturnAccess;
use App\Returns\ReturnEligibility;
use App\Returns\ReturnPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ready-goods returns (M-15, FUL-11, P04): ReturnList (delivered orders that may return goods and the returns),
 * ReturnNew (request on an order), ReturnPage (decision, receipt + inspection, withdrawal, correction, refund). Writes
 * are Actions that authorize their own grant on the return; this class reads, validates the envelope and answers
 * (JSON for API callers, Inertia redirects for the screens).
 */
final class ReturnController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private readonly ReturnPresenter $presenter) {}

    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(ReturnAccess::any($actor), 403);

        $status = ReturnStatus::tryFrom((string) $request->query('status'))?->value;
        $q = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $returns = ReturnAccess::scoped(OrderReturn::query(), $actor)->with(['order.customer'])
            ->when($status, fn ($query) => $query->where('order_returns.status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('order_returns.number', 'like', $like)
                ->orWhereIn('order_returns.order_id', Order::query()->where('number', 'like', $like)->select('id'))))
            ->orderByDesc('order_returns.id')->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (OrderReturn $r) => ReturnPresenter::row($r, ReturnAccess::canSeeAmounts($actor, $r->order)));

        $payload = [
            'eligible' => $this->eligibleOrders($actor),
            'returns' => $returns,
            'filters' => ['status' => $status, 'q' => $q],
            'settings' => ['window_days' => ReturnSettings::current()->window_days, 'can_edit' => Access::can($actor, Permission::P02)],
        ];

        return $this->render($request, 'returns/index', $payload);
    }

    public function create(Request $request, Order $order): Response|JsonResponse
    {
        $actor = $this->actor($request);
        if (! ReturnAccess::canRequest($actor, $order)) {
            Access::authorize($actor, Permission::P53, $order);
        }
        abort_unless($order->isReady(), 409);
        $order->load('customer');

        $payload = [
            'order' => ReturnPresenter::orderRef($order), 'lines' => ReturnPresenter::eligible($order, $actor),
            'window_days' => ReturnSettings::current()->window_days,
        ];

        return $this->render($request, 'returns/form', $payload);
    }

    public function store(StoreReturnRequest $request, Order $order, T00RequestReturn $action): RedirectResponse|JsonResponse
    {
        $return = $action->handle($this->actor($request), $order, $request->lineData(), $request->reasonText(), $request->key());

        return $this->answer($request, $return, __('returns.flash.requested', ['number' => $return->number]), 201);
    }

    public function show(Request $request, OrderReturn $orderReturn): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(ReturnAccess::canView($actor, $orderReturn), 403);

        return $this->render($request, 'returns/show', ['return' => $this->presenter->detail($orderReturn, $actor)]);
    }

    public function accept(AcceptReturnRequest $request, OrderReturn $orderReturn, T05AcceptReturn $action): RedirectResponse|JsonResponse
    {
        $done = $action->handle($this->actor($request), $orderReturn, $request->acceptedLines(), $request->reasonText(), $request->settlementMinor(), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('returns.flash.accepted'));
    }

    public function reject(ReturnReasonRequest $request, OrderReturn $orderReturn, T06RejectReturn $action): RedirectResponse|JsonResponse
    {
        $done = $action->handle($this->actor($request), $orderReturn, $request->reasonText(), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('returns.flash.rejected'));
    }

    public function withdraw(ReturnReasonRequest $request, OrderReturn $orderReturn, T03WithdrawReturn $action): RedirectResponse|JsonResponse
    {
        $done = $action->handle($this->actor($request), $orderReturn, $request->reasonText(), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('returns.flash.withdrawn'));
    }

    public function receive(ReceiveReturnRequest $request, OrderReturn $orderReturn, T01ReceiveReturn $action): RedirectResponse|JsonResponse
    {
        $done = $action->handle($this->actor($request), $orderReturn, (int) $request->validated('location_id'), $request->receiptLines(),
            $request->boolean('inspect'), $request->noteText(), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('returns.flash.received'));
    }

    public function inspect(InspectReturnRequest $request, OrderReturn $orderReturn, T02InspectReturn $action): RedirectResponse|JsonResponse
    {
        $done = $action->handle($this->actor($request), $orderReturn, $request->results(), $request->noteText(), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('returns.flash.inspected'));
    }

    public function correct(CorrectReturnRequest $request, OrderReturn $orderReturn, T04CorrectInspection $action): RedirectResponse|JsonResponse
    {
        $done = $action->handle($this->actor($request), $orderReturn, (int) $request->validated('part_id'), $request->goodMilli(), $request->reasonText(), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('returns.flash.corrected'));
    }

    public function refund(ReturnRefundRequest $request, OrderReturn $orderReturn, RequestReturnRefund $action): RedirectResponse|JsonResponse
    {
        $refund = $action->handle($this->actor($request), $orderReturn, $request->refundData(), $request->lockVersion(), (string) $request->key());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['refund_id' => $refund->id, 'return' => $this->presenter->detail($orderReturn->refresh(), $this->actor($request))], 201);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('returns.flash.refund_requested')]);

        return to_route('returns.show', $orderReturn);
    }

    public function settings(ReturnSettingsRequest $request, UpdateReturnSettings $action): RedirectResponse|JsonResponse
    {
        $settings = $action->handle($this->actor($request), (int) $request->validated('window_days'));
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['window_days' => $settings->window_days]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('returns.flash.settings')]);

        return to_route('returns.index');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function render(Request $request, string $page, array $payload): Response|JsonResponse
    {
        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render($page, $payload);
    }

    private function answer(Request $request, OrderReturn $return, string $message, int $status = 200): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['return' => $this->presenter->detail($return->refresh(), $this->actor($request))], $status);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('returns.show', $return);
    }

    /**
     * Ready orders in the user's request scope with delivered goods still eligible for a return.
     *
     * @return list<array<string, mixed>>
     */
    private function eligibleOrders(User $actor): array
    {
        $rows = [];
        $orders = ReturnAccess::orders($actor)->where('kind', OrderKind::Ready->value)->whereIn('status', ['partially_delivered', 'delivered'])
            ->with('customer')->orderByDesc('id')->limit(100)->get();
        foreach ($orders as $order) {
            if (! ReturnAccess::canRequest($actor, $order)) {
                continue;
            }
            $lines = ReturnEligibility::lines($order);
            $eligible = array_sum(array_column($lines, 'eligible'));
            if ($eligible === 0) {
                continue;
            }
            $rows[] = ['id' => $order->id, 'number' => $order->number, 'status' => $order->status, 'customer' => $order->customer->name, 'lines' => count($lines)];
        }

        return $rows;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
