<?php

namespace App\Http\Controllers\Portal;

use App\Access\Exceptions\InvalidTransition;
use App\Agreements\CustomerDecisions;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\Finance\Receipt;
use App\Portal\PortalAccess;
use App\Portal\PortalPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CustDocs and DocApprove (P-66/P-67, WEB-10): the customer's documents by type and date, and the decision on the exact
 * content shown (its content hash travels with the decision; changed content is refused by the decision service).
 */
final class DocumentController extends PortalController
{
    public function __construct(private readonly CustomerDecisions $decisions) {}

    public function index(): Response
    {
        $customer = $this->customer();
        $documents = PortalAccess::documents($customer)->with('order')->orderByDesc('sent_at')->limit(200)->get();
        $receipts = PortalAccess::receipts($customer)->with('order')->orderByDesc('issued_at')->limit(200)->get();

        return Inertia::render('portal/documents', [
            'documents' => array_values($documents->map(fn (AgreementDocument $d) => PortalPresenter::documentSummary($d, $d->order))->all()),
            'receipts' => array_values($receipts->map(fn (Receipt $r) => PortalPresenter::receipt($r, $r->order))->all()),
        ]);
    }

    public function show(string $document): Response
    {
        $customer = $this->customer();

        return Inertia::render('portal/document', [
            'document' => PortalPresenter::document($customer, PortalAccess::document($customer, $document)),
        ]);
    }

    public function approve(Request $request, string $document): RedirectResponse
    {
        $data = $request->validate(['content_hash' => ['required', 'string', 'size:64']]);
        $record = PortalAccess::document($this->customer(), $document);

        try {
            $this->decisions->approveDocument($this->actor(), $record, $data['content_hash']);
        } catch (InvalidTransition $e) {
            // A repeated approval of the same content (double tap, second tab) is answered as done, never recorded twice.
            if (! self::alreadyApproved($record, $data['content_hash'])) {
                throw $e;
            }
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.documents.approved')]);

        return redirect()->route('customer.documents.show', $record->id);
    }

    public function requestChanges(Request $request, string $document): RedirectResponse
    {
        $data = $request->validate([
            'content_hash' => ['required', 'string', 'size:64'],
            'note' => ['required', 'string', 'max:1000'],
        ]);
        $record = PortalAccess::document($this->customer(), $document);
        $this->decisions->requestDocumentChanges($this->actor(), $record, $data['content_hash'], $data['note']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.documents.changes_sent')]);

        return redirect()->route('customer.documents.show', $record->id);
    }

    private static function alreadyApproved(AgreementDocument $document, string $hash): bool
    {
        return CustomerDecision::query()->where('subject_type', 'document')->where('subject_id', $document->id)
            ->where('decision', CustomerDecision::APPROVED)->where('content_hash', $hash)->exists();
    }
}
