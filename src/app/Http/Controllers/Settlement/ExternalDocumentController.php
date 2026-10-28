<?php

namespace App\Http\Controllers\Settlement;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Actions\StateMachines\M40Document\T00RequestDocument;
use App\Actions\StateMachines\M40Document\T01AttachDocument;
use App\Actions\StateMachines\M40Document\T02AcceptDocument;
use App\Actions\StateMachines\M40Document\T03RequestDocumentCorrection;
use App\Finance\Settlement\SettlementPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\DocumentAttachRequest;
use App\Http\Requests\Settlement\DocumentRequestRequest;
use App\Http\Requests\Settlement\KeyRequest;
use App\Http\Requests\Settlement\SettlementReasonRequest;
use App\Models\Finance\ExternalDocument;
use App\Models\Order;
use App\Orders\OrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * External accounting documents (M-40, P-44, FIN-10): FinInvoices (requested and linked documents of the orders in
 * the viewer's P-44 scope) and FinInvoiceLink (link, review, correction). OpsOne never issues a tax invoice (BR-20).
 */
class ExternalDocumentController extends Controller
{
    use SettlementResponses;

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $orders = ScopedQuery::apply(Order::query(), $actor, [Permission::P44]);
        abort_unless(Access::can($actor, Permission::P44), 403);
        $documents = ExternalDocument::query()->with(['order.customer', 'payment.receipt', 'refund', 'reviewer', 'attachments'])
            ->whereIn('order_id', (clone $orders)->select('orders.id'))
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'needs_correction' THEN 1 WHEN 'attached' THEN 2 ELSE 3 END")
            ->orderByDesc('id')->limit(300)->get();

        return Inertia::render('finance/invoices', [
            'documents' => array_values($documents->map(fn (ExternalDocument $d) => [
                ...SettlementPresenter::document($d, $actor, OrderAccess::canSeeFinancials($actor, $d->order), true),
                'order' => ['id' => $d->order->id, 'number' => $d->order->number, 'customer' => $d->order->customer->name],
            ])->all()),
        ]);
    }

    public function store(DocumentRequestRequest $request, Order $order, T00RequestDocument $action): JsonResponse
    {
        $document = $action->handle($this->actor($request), $order, $request->body(), $request->key());

        return $this->respond($request, $order, ['document_id' => $document->id], 201);
    }

    public function link(DocumentAttachRequest $request, Order $order, T01AttachDocument $action): JsonResponse
    {
        $body = array_filter(collect($request->body())->except('file')->all(), fn ($v) => $v !== null);
        $document = $action->handle($this->actor($request), $order, null, $body, $request->document(), $request->lockVersion(), $request->key());

        return $this->respond($request, $order, ['document_id' => $document->id], 201);
    }

    public function attach(DocumentAttachRequest $request, ExternalDocument $document, T01AttachDocument $action): JsonResponse
    {
        $body = collect($request->body())->only(['source', 'number', 'issued_on', 'amount', 'currency'])->filter(fn ($v) => $v !== null)->all();
        $action->handle($this->actor($request), $document->order, $document, $body, $request->document(), $request->lockVersion(), $request->key());

        return $this->respond($request, $document->order);
    }

    public function accept(KeyRequest $request, ExternalDocument $document, T02AcceptDocument $action): JsonResponse
    {
        $action->handle($this->actor($request), $document, $request->lockVersion(), $request->key());

        return $this->respond($request, $document->order);
    }

    public function correction(SettlementReasonRequest $request, ExternalDocument $document, T03RequestDocumentCorrection $action): JsonResponse
    {
        $action->handle($this->actor($request), $document, (string) $request->validated('reason'), $request->lockVersion(), $request->key());

        return $this->respond($request, $document->order);
    }
}
