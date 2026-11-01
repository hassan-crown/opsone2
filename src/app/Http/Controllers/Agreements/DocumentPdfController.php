<?php

namespace App\Http\Controllers\Agreements;

use App\Agreements\AgreementPresenter;
use App\Agreements\Signing\DocumentPdfs;
use App\Agreements\Signing\DocumentSheet;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DocumentSignature;
use App\Models\User;
use App\Orders\OrderAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download or print the branded PDF of a quote/contract (ADR-0029). The PDF shows every amount, so it needs the order
 * (P-16) and its amounts (P-37, or P-29 who prices) — the same rule as the agreement payload. A draft is a preview
 * rendered on the fly; a sent document serves its stored PDF; a signed one its signed PDF (or `version=original`).
 */
final class DocumentPdfController extends Controller
{
    public function __invoke(Request $request, AgreementDocument $document, DocumentPdfs $pdfs): Response
    {
        /** @var User $user */
        $user = $request->user();
        $order = $document->order;
        abort_unless(OrderAccess::canView($user, $order) && AgreementPresenter::amountsVisible($user, $order), 403);
        abort_unless(DocumentSheet::supports($document), 404);
        $locale = DocumentPdfs::locale((string) $request->query('locale', app()->getLocale()));
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $headers = ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0'];

        if ($document->isDraft()) {
            return response($pdfs->preview($document, $locale), 200, $headers + [
                'Content-Disposition' => $disposition.'; filename="'.DocumentPdfs::fileName($document, $locale).'"',
            ]);
        }

        $signature = DocumentSignature::query()->where('agreement_document_id', $document->id)->first();
        $pdf = $signature !== null && $request->query('version') !== 'original'
            ? $pdfs->signed($signature)
            : $pdfs->current($document, $locale, $user);
        $file = $pdf->attachment;
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);
        DB::transaction(fn () => Audit::record(AuditAction::AttachmentDownloaded, $document, $user, after: [
            'attachment' => $file->uuid, 'name' => $file->original_name,
        ]));

        return $disposition === 'attachment'
            ? $disk->download($file->path, $file->original_name, $headers)
            : $disk->response($file->path, $file->original_name, $headers);
    }
}
