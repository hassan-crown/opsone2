<?php

namespace App\Http\Controllers\Delivery;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M14Delivery\T00CreateDraft;
use App\Actions\StateMachines\M14Delivery\T01ConfirmDelivery;
use App\Actions\StateMachines\M14Delivery\T02CancelDraft;
use App\Delivery\DeliveryAccess;
use App\Delivery\DeliveryPresenter;
use App\Delivery\DeliveryProgress;
use App\Enums\CustomOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OrderKind;
use App\Enums\ReadyOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\ConfirmRequest;
use App\Http\Requests\Delivery\DeliveryRequest;
use App\Http\Requests\Delivery\ReasonRequest;
use App\Models\Delivery\DeliveryNote;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deliveries (M-14, FUL-09/FUL-10): DelList (what is owed and the notes), DelNew (draft on an order), DelRecord (the note
 * page that records or withdraws). Writes are Actions that authorize P-52 on the order; this class reads, validates the
 * envelope and answers (JSON for API callers, Inertia redirects for the screens).
 */
final class DeliveryController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private readonly DeliveryPresenter $presenter) {}

    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(DeliveryAccess::any($actor), 403);

        $status = DeliveryStatus::tryFrom((string) $request->query('status'))?->value;
        $q = trim((string) $request->query('q', ''));
        $notes = DeliveryAccess::scoped(DeliveryNote::query(), $actor)->with(['order.customer'])
            ->when($status, fn ($query) => $query->where('delivery_notes.status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('delivery_notes.number', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhereIn('delivery_notes.order_id', Order::query()->where('number', 'like', '%'.addcslashes($q, '%_\\').'%')->select('id'))))
            ->orderByDesc('delivery_notes.id')->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (DeliveryNote $n) => DeliveryPresenter::row($n));

        $payload = [
            'awaiting' => $this->awaiting($actor),
            'notes' => $notes,
            'filters' => ['status' => $status, 'q' => $q],
        ];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('deliveries/index', $payload);
    }

    public function create(Request $request, Order $order): Response|JsonResponse
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P52, $order);
        abort_unless(DeliveryProgress::accepts($order), 409);
        $order->load('customer');

        $payload = ['order' => DeliveryPresenter::orderRef($order), 'lines' => DeliveryPresenter::owed($order, $actor)];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('deliveries/form', $payload);
    }

    public function store(DeliveryRequest $request, Order $order, T00CreateDraft $create): RedirectResponse|JsonResponse
    {
        $note = $create->handle($this->actor($request), $order, $request->lineData(), $request->noteText(), $request->key());

        return $this->answer($request, $note, __('delivery.drafted'), 201);
    }

    public function show(Request $request, DeliveryNote $note): Response|JsonResponse
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P52, $note);
        $payload = ['note' => $this->presenter->detail($note, $actor)];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('deliveries/show', $payload);
    }

    public function confirm(ConfirmRequest $request, DeliveryNote $note, T01ConfirmDelivery $confirm): RedirectResponse|JsonResponse
    {
        $done = $confirm->handle($this->actor($request), $note, $request->deliveryData(), $request->proofs(), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('delivery.confirmed', ['number' => $done->number]));
    }

    public function cancel(ReasonRequest $request, DeliveryNote $note, T02CancelDraft $cancel): RedirectResponse|JsonResponse
    {
        $done = $cancel->handle($this->actor($request), $note, (string) $request->validated('reason'), $request->lockVersion(), $request->key());

        return $this->answer($request, $done, __('delivery.cancelled'));
    }

    private function answer(Request $request, DeliveryNote $note, string $message, int $status = 200): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['note' => $this->presenter->detail($note->refresh(), $this->actor($request))], $status);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('deliveries.show', $note);
    }

    /**
     * Orders in the user's delivery scope that still owe something (the «ready for delivery» side, ready and custom).
     *
     * @return list<array<string, mixed>>
     */
    private function awaiting(User $actor): array
    {
        $open = [ReadyOrderStatus::Confirmed->value, ReadyOrderStatus::PartiallyDelivered->value, CustomOrderStatus::InProgress->value, CustomOrderStatus::ExecutionFinished->value];
        $rows = [];
        foreach (DeliveryAccess::orders($actor)->whereIn('status', $open)->with('customer')->orderBy('id')->limit(200)->get() as $order) {
            $lines = DeliveryProgress::lines($order);
            $owed = array_sum(array_column($lines, 'deliverable'));
            if ($owed === 0 || ! DeliveryProgress::accepts($order)) {
                continue;
            }
            $rows[] = [
                'id' => $order->id, 'number' => $order->number, 'kind' => $order->kind->value, 'status' => $order->status,
                'customer' => $order->customer->name, 'lines' => count($lines),
                'partial' => array_sum(array_column($lines, 'delivered')) > 0,
                'ready' => $order->kind === OrderKind::Ready,
            ];
        }

        return $rows;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
