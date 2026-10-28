<?php

namespace App\Http\Controllers\Cancellation;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M34Cancellation\T01RequestCancellation;
use App\Actions\StateMachines\M34Cancellation\T02AcceptCancellation;
use App\Actions\StateMachines\M34Cancellation\T03RecordAndAcceptCancellation;
use App\Actions\StateMachines\M34Cancellation\T04RejectCancellation;
use App\Cancellation\CancellationImpact;
use App\Cancellation\CancellationInput;
use App\Cancellation\CancellationPresenter;
use App\Cancellation\CancellationWrite;
use App\Enums\CancellationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cancellation\CancellationRequest;
use App\Http\Requests\Cancellation\DecisionReasonRequest;
use App\Http\Requests\Cancellation\PreviewRequest;
use App\Http\Requests\Cancellation\RecordRequest;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Order;
use App\Models\Settlement\SettlementItem;
use App\Models\User;
use App\Settlement\SettlementFollowUps;
use App\Support\LockingTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CancelReview (screen 115, FUL-12 / COM-14 / WF-13 / FIN-14) and the settlement follow-up (INT-08). The page and the
 * preview read; every write is an M-34 Action that authorizes P-18 on the order (settlement items: the permission of
 * the action they stand for) and carries an idempotency key and the version the screen read.
 */
final class CancellationController extends Controller
{
    public function __construct(private readonly SettlementFollowUps $settlement) {}

    public function show(Request $request, Order $order): Response|JsonResponse
    {
        $actor = $this->actor($request);
        $followUp = SettlementFollowUps::forOrder($order);
        abort_unless(Access::can($actor, Permission::P18, $order) || ($followUp !== null && Access::can($actor, Permission::P16, $order)), 403);
        if ($followUp !== null && $followUp->isOpen()) {
            // Like the finance read (OrderSchedule::sync): a refund executed meanwhile closes its item before showing it.
            LockingTransaction::run(fn () => $this->settlement->sync(Order::query()->lockForUpdate()->findOrFail($order->id)));
        }
        $payload = CancellationPresenter::page($order->refresh(), $actor);

        return $this->wantsJson($request) ? response()->json($payload) : Inertia::render('orders/cancel', $payload);
    }

    /**
     * The impact review (read only): what stops, what stays, what needs settlement, and the fingerprint to confirm.
     */
    public function preview(PreviewRequest $request, Order $order): JsonResponse
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P18, $order);
        $input = CancellationInput::fromInput($request->cancellationData());
        CancellationWrite::authorizeAmounts($actor, $order, $input);
        $pending = OrderCancellation::query()->where('active_key', $order->id)->first();
        $impact = CancellationImpact::compute($order, $input, $pending);

        return response()->json([
            'impact' => CancellationPresenter::impact($impact, $actor, $order),
            'hash' => $impact['hash'],
            'lock_version' => $order->lock_version,
        ]);
    }

    public function request(RecordRequest $request, Order $order, T01RequestCancellation $record): RedirectResponse|JsonResponse
    {
        $cancellation = $record->handle($this->actor($request), $order, $request->cancellationData(), $request->lockVersion(), $request->key());

        return $this->answer($request, $cancellation, __('cancel.requested', ['number' => $cancellation->number]), 201);
    }

    public function store(CancellationRequest $request, Order $order, T03RecordAndAcceptCancellation $accept): RedirectResponse|JsonResponse
    {
        $cancellation = $accept->handle($this->actor($request), $order, $request->cancellationData(), $request->hash(), $request->lockVersion(), $request->key());

        return $this->answer($request, $cancellation, __('cancel.accepted', ['number' => $cancellation->number]), 201);
    }

    public function accept(CancellationRequest $request, OrderCancellation $cancellation, T02AcceptCancellation $accept): RedirectResponse|JsonResponse
    {
        $done = $accept->handle($this->actor($request), $cancellation, $request->cancellationData(), $request->hash(), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('cancel.accepted', ['number' => $done->number]));
    }

    public function reject(DecisionReasonRequest $request, OrderCancellation $cancellation, T04RejectCancellation $reject): RedirectResponse|JsonResponse
    {
        $done = $reject->handle($this->actor($request), $cancellation, (string) $request->validated('reason'), $request->lockVersion());

        return $this->answer($request, $done, __('cancel.rejected'));
    }

    public function resolve(DecisionReasonRequest $request, SettlementItem $item): RedirectResponse|JsonResponse
    {
        $actor = $this->actor($request);
        $order = $item->order;
        $this->settlement->resolve($actor, $item, (string) ($request->validated('status') ?? SettlementItem::DONE), (string) $request->validated('reason'), $request->lockVersion());

        if ($this->wantsJson($request)) {
            return response()->json(['settlement' => CancellationPresenter::followUp(SettlementFollowUps::forOrder($order), $actor, $order)]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('settlement.resolved')]);

        return to_route('orders.cancellation', $order);
    }

    private function answer(Request $request, OrderCancellation $cancellation, string $message, int $status = 200): RedirectResponse|JsonResponse
    {
        $order = Order::query()->findOrFail($cancellation->order_id);
        if ($this->wantsJson($request)) {
            $actor = $this->actor($request);

            return response()->json([
                'cancellation' => ['id' => $cancellation->id, 'number' => $cancellation->number, 'status' => $cancellation->status->value, 'scope' => $cancellation->scope],
                'order' => ['id' => $order->id, 'status' => $order->status, 'lock_version' => $order->lock_version],
                'settlement' => CancellationPresenter::followUp(SettlementFollowUps::forOrder($order), $actor, $order),
            ], $cancellation->status === CancellationStatus::Pending ? 201 : $status);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('orders.cancellation', $order);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
