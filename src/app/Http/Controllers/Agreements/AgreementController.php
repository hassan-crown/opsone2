<?php

namespace App\Http\Controllers\Agreements;

use App\Actions\StateMachines\M08Document\T00CreateQuote;
use App\Actions\StateMachines\M08Document\T00PrepareContract;
use App\Actions\StateMachines\M08Document\T00UploadDesign;
use App\Actions\StateMachines\M09Change\T00CreateChange;
use App\Agreements\AgreementPresenter;
use App\Enums\OrderKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\ChangeCreateRequest;
use App\Http\Requests\Agreements\ContractRequest;
use App\Http\Requests\Agreements\DesignUploadRequest;
use App\Http\Requests\Agreements\KeyOnlyRequest;
use App\Models\Agreements\AgreementDocument;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderLine;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Orders\OrderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Agreement and documents» of an order (AgrOrder, AgrEmpty, AgrMobile, AgrSupervisor, AgrEnglish, AgrDark): the page
 * and the creating endpoints. Reads need P-16 over the order; amounts follow AgreementPresenter. Contract:
 * docs/p6-finance-api.md.
 */
class AgreementController extends Controller
{
    public function show(Request $request, Order $order): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(OrderAccess::canView($actor, $order), 403);
        abort_if($order->kind !== OrderKind::Custom, 404);
        $agreement = AgreementPresenter::forOrder($order, $actor);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['agreement' => $agreement]);
        }

        return Inertia::render('orders/agreement', [
            'order' => OrderPresenter::row($order, $actor) + ['needs_measurement' => $order->needs_measurement, 'needs_design' => $order->needs_design],
            'agreement' => $agreement,
            'lines' => array_values(OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->orderBy('position')->get()
                ->map(fn (OrderLine $l) => OrderPresenter::line($l, false))->all()),
            'actions' => array_values(OrderAction::query()->where('order_id', $order->id)->orderBy('order_stage_id')->orderBy('position')->get()
                ->map(fn (OrderAction $a) => ['key' => $a->key, 'name' => $a->name->get(), 'type' => $a->type->value, 'status' => $a->status->value])->all()),
        ]);
    }

    public function storeQuote(KeyOnlyRequest $request, Order $order, T00CreateQuote $create): JsonResponse
    {
        $document = $create->handle($this->actor($request), $order, $request->key());

        return $this->created($request, $order, ['document_id' => $document->id]);
    }

    public function storeDesign(DesignUploadRequest $request, Order $order, T00UploadDesign $upload): JsonResponse
    {
        $replaces = $request->validated('replaces_id') === null ? null
            : AgreementDocument::query()->where('order_id', $order->id)->findOrFail((int) $request->validated('replaces_id'));
        $document = $upload->handle($this->actor($request), $order, $request->upload(), (string) $request->validated('description'),
            array_values(array_map(intval(...), $request->validated('lines') ?? [])), $replaces, $request->key());

        return $this->created($request, $order, ['document_id' => $document->id]);
    }

    public function storeContract(ContractRequest $request, Order $order, T00PrepareContract $prepare): JsonResponse
    {
        $document = $prepare->handle($this->actor($request), $order, $request->upload(), $request->validated('terms'), $request->key());

        return $this->created($request, $order, ['document_id' => $document->id]);
    }

    public function storeChange(ChangeCreateRequest $request, Order $order, T00CreateChange $create): JsonResponse
    {
        $change = $create->handle($this->actor($request), $order, (string) $request->validated('description'), $request->key());

        return $this->created($request, $order, ['change_id' => $change->id]);
    }

    /**
     * @param  array<string, int>  $ids
     */
    private function created(Request $request, Order $order, array $ids): JsonResponse
    {
        return response()->json([...$ids, 'agreement' => AgreementPresenter::forOrder($order->refresh(), $this->actor($request))], 201);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
