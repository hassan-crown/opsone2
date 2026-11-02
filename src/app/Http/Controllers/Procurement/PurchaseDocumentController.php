<?php

namespace App\Http\Controllers\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Http\Requests\Procurement\EmailPurchaseRequest;
use App\Http\Requests\Procurement\PurchaseDocumentRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderSend;
use App\Procurement\PurchaseAccess;
use App\Procurement\PurchaseDocumentLayout;
use App\Procurement\PurchaseOrderDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The purchase order as a document (ADR-0038): its PDF (view P-46/P-47 on the order), emailing it to the supplier and
 * retrying a failed send (P-46), and the facility's document settings with a live preview (P-45).
 */
final class PurchaseDocumentController extends ProcurementController
{
    public function __construct(private readonly PurchaseOrderDocuments $documents) {}

    public function pdf(Request $request, PurchaseOrder $purchase): HttpResponse
    {
        $actor = $this->actor($request);
        abort_unless(PurchaseAccess::canView($actor, $purchase), 403);
        $locale = $request->query('locale') === 'en' ? 'en' : 'ar';
        $document = $this->documents->document($actor, $purchase, $locale);
        $file = $document->attachment()->firstOrFail();

        return response((string) Storage::disk($file->disk)->get($file->path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$purchase->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function email(EmailPurchaseRequest $request, PurchaseOrder $purchase): RedirectResponse
    {
        $this->documents->send($this->actor($request), $purchase, $request->body(), $request->key());
        $this->toast(__('purchase_document.queued'));

        return to_route('purchases.show', $purchase);
    }

    public function retry(EmailPurchaseRequest $request, PurchaseOrder $purchase, PurchaseOrderSend $send): RedirectResponse
    {
        abort_unless($send->purchase_order_id === $purchase->id, 404);
        // Only a send whose retries ran out is repeated by hand; a queued one is still being retried by the outbox.
        abort_unless($send->status === PurchaseOrderSend::FAILED, 409);
        $this->documents->send($this->actor($request), $purchase, [
            'recipient' => $send->recipient, 'subject' => $send->subject, 'body' => $send->body, 'locale' => $send->locale,
        ], $request->key(), $send);
        $this->toast(__('purchase_document.queued'));

        return to_route('purchases.show', $purchase);
    }

    public function settings(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P45), 403);
        $current = PurchaseDocumentLayout::current();

        return Inertia::render('purchases/document-settings', [
            'version' => $current['version'],
            'layout' => $current['layout'],
            'email' => $current['email'],
            'options' => [
                'blocks' => PurchaseDocumentLayout::BLOCKS,
                'flags' => array_keys(PurchaseDocumentLayout::FLAGS),
                'placeholders' => PurchaseDocumentLayout::PLACEHOLDERS,
            ],
        ]);
    }

    public function save(PurchaseDocumentRequest $request): RedirectResponse
    {
        $version = $this->documents->saveLayout($this->actor($request), $request->layout(), $request->email(), (int) $request->validated('version'));
        $this->toast(__('purchase_document.saved', ['version' => $version]));

        return to_route('settings.purchase-document');
    }

    public function preview(PurchaseDocumentRequest $request): HttpResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P45), 403);
        $bytes = $this->documents->preview($actor, $request->layout(), $request->validated('locale') === 'en' ? 'en' : 'ar');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
