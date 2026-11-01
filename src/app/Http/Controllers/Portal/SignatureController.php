<?php

namespace App\Http\Controllers\Portal;

use App\Agreements\Signing\DocumentPdfs;
use App\Agreements\Signing\DocumentSheet;
use App\Agreements\Signing\ESignature;
use App\Agreements\Signing\SignLinks;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentStatus;
use App\Http\Requests\Portal\SignDocumentRequest;
use App\Models\Agreements\DocumentPdf;
use App\Models\Agreements\DocumentSignature;
use App\Models\Agreements\DocumentSignLink;
use App\Portal\OtpThrottled;
use App\Portal\PortalAccess;
use App\Portal\PortalSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DocSign (ADR-0029): the customer reads the quote/contract PDF, asks for a step-up code and signs; a signing link
 * staff sent lands here after sign-in. Every document is looked up through PortalAccess (another customer's id = 404).
 */
final class SignatureController extends PortalController
{
    private const LINK_SESSION = 'esign.link';

    public function __construct(
        private readonly ESignature $signatures,
        private readonly DocumentPdfs $pdfs,
    ) {}

    public function pdf(Request $request, string $document): StreamedResponse
    {
        $record = PortalAccess::document($this->customer(), $document);
        abort_unless(DocumentSheet::supports($record), 404);
        $signature = DocumentSignature::query()->where('agreement_document_id', $record->id)->first();
        $locale = (string) $request->query('locale', $signature === null ? app()->getLocale() : $signature->locale);
        $pdf = $signature !== null && $request->query('version') !== 'original'
            ? $this->pdfs->signed($signature)
            : $this->pdfs->current($record, $locale);

        return self::stream($pdf, $request->boolean('download'));
    }

    public function code(Request $request, string $document): RedirectResponse
    {
        $customer = $this->customer();
        $record = PortalAccess::document($customer, $document);
        try {
            $destination = $this->signatures->requestCode($customer, $record, $request->ip());
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['code' => SignInController::throttled($e)]);
        }
        if ($destination === null) {
            throw ValidationException::withMessages(['code' => __('esign.error.no_channel')]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('esign.code_sent', ['to' => $destination])]);

        return redirect()->route('customer.documents.show', ['document' => $record->id, 'sign' => 1]);
    }

    public function store(SignDocumentRequest $request, string $document): RedirectResponse
    {
        $customer = $this->customer();
        $record = PortalAccess::document($customer, $document);
        $linkId = $request->session()->get(self::LINK_SESSION.'.'.$record->id);
        $link = is_numeric($linkId) ? DocumentSignLink::query()->whereKey((int) $linkId)->first() : null;

        try {
            $this->signatures->sign($customer, $record, $request->signing(), $request->ip(), $request->userAgent(), $link);
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['code' => SignInController::throttled($e)]);
        }
        $request->session()->forget(self::LINK_SESSION.'.'.$record->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('esign.signed')]);

        return redirect()->route('customer.documents.show', $record->id);
    }

    /**
     * A signing link: only the order's customer gets past it, and only while it is usable; anything else shows the
     * same «no longer valid» page (existence is not revealed).
     */
    public function open(Request $request, string $token): SymfonyResponse
    {
        $customer = $this->customer();
        $link = SignLinks::find($token);
        if ($link === null || $link->document->order->customer_id !== $customer->id) {
            return Inertia::render('portal/sign-link', ['state' => 'invalid'])->toResponse($request)->setStatusCode(404);
        }
        if (DocumentSignature::query()->where('agreement_document_id', $link->agreement_document_id)->exists()
            || $link->document->status === DocumentStatus::Approved) {
            return redirect()->route('customer.documents.show', $link->agreement_document_id);
        }
        if (! SignLinks::usable($link)) {
            return Inertia::render('portal/sign-link', ['state' => 'expired', 'document_id' => $link->agreement_document_id])
                ->toResponse($request)->setStatusCode(410);
        }
        $request->session()->put(self::LINK_SESSION.'.'.$link->agreement_document_id, $link->id);

        return redirect()->route('customer.documents.show', ['document' => $link->agreement_document_id, 'sign' => 1]);
    }

    private static function stream(DocumentPdf $pdf, bool $download): StreamedResponse
    {
        $file = $pdf->attachment;
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);
        DB::transaction(fn () => Audit::record(AuditAction::AttachmentDownloaded, $pdf->document, null, after: [
            'attachment' => $file->uuid, 'name' => $file->original_name, 'by' => 'customer',
        ], source: PortalSession::SOURCE));

        $headers = [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ];

        return $download
            ? $disk->download($file->path, $file->original_name, $headers)
            : $disk->response($file->path, $file->original_name, $headers);
    }
}
