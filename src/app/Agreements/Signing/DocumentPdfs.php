<?php

namespace App\Agreements\Signing;

use App\Enums\DocumentStatus;
use App\Files\AttachmentService;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DocumentPdf;
use App\Models\Agreements\DocumentSignature;
use App\Models\Attachment;
use App\Models\Facility;
use App\Models\User;
use App\Portal\CustomerOtp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * The PDFs of quotes and contracts (ADR-0029). A sent document's PDF is stored once per language as a private
 * attachment of the document with its sha256 and fingerprint (DocumentSheet): while the document awaits the customer a
 * PDF is generated again only when what it shows changes; once decided, signed, replaced or cancelled the last PDF
 * of that language is kept as is. A draft is rendered on the fly and never stored. The signed PDF is the PDF the
 * customer signed with a signature certificate page appended, generated once.
 */
final class DocumentPdfs
{
    public const LOCALES = ['ar', 'en'];

    public const PURPOSE = 'document_pdf';

    public const SIGNED_PURPOSE = 'document_pdf_signed';

    /** Files of a document the application produced: listed by their own blocks, never among the document's files. */
    public const GENERATED_PURPOSES = [self::PURPOSE, self::SIGNED_PURPOSE, ESignature::SIGNATURE_PURPOSE];

    /**
     * The document's own files (uploaded design, evidence), without generated PDFs and signature images.
     *
     * @return Collection<int, Attachment>
     */
    public static function ownFiles(AgreementDocument $document): Collection
    {
        return $document->attachments()->where(fn ($q) => $q->whereNull('purpose')->orWhereNotIn('purpose', self::GENERATED_PURPOSES))->get();
    }

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AttachmentService $files,
    ) {}

    /**
     * Bytes of a draft preview (never stored).
     */
    public function preview(AgreementDocument $document, string $locale): string
    {
        $sheet = DocumentSheet::build($document, self::locale($locale));

        return $this->renderer->render(self::html($sheet), $sheet->images, $document->number);
    }

    /**
     * The stored PDF of a sent document in a language: generated when missing or, while the customer may still decide,
     * when its fingerprint changed. Never call for a draft.
     */
    public function current(AgreementDocument $document, string $locale, ?User $actor = null): DocumentPdf
    {
        $locale = self::locale($locale);
        if ($document->status === DocumentStatus::Draft) {
            throw new LogicException('A draft is previewed, never stored.');
        }

        $latest = self::latest($document, DocumentPdf::ORIGINAL, $locale);
        $frozen = $document->status !== DocumentStatus::AwaitingCustomer
            || DocumentSignature::query()->where('agreement_document_id', $document->id)->exists();
        if ($latest !== null && $frozen) {
            return $latest;
        }

        $sheet = DocumentSheet::build($document, $locale);
        if ($latest !== null && $latest->fingerprint === $sheet->fingerprint) {
            return $latest;
        }

        $bytes = $this->renderer->render(self::html($sheet), $sheet->images, $document->number);

        return $this->store($document, DocumentPdf::ORIGINAL, $locale, $sheet->fingerprint, $bytes, $actor);
    }

    /**
     * The signed PDF of a signature: the signed original's pages plus the certificate page, generated once and
     * attached to the signature.
     */
    public function signed(DocumentSignature $signature): DocumentPdf
    {
        if ($signature->signed_pdf_id !== null) {
            return $signature->signedPdf ?? throw new LogicException('Signed PDF row missing.');
        }

        $document = $signature->document;
        $original = $signature->pdf;
        $disk = Storage::disk($original->attachment->disk);
        $bytes = (string) $disk->get($original->attachment->path);
        if (! hash_equals($original->sha256, hash('sha256', $bytes))) {
            throw new LogicException('The signed PDF no longer matches its hash.');
        }

        $certificate = $this->certificate($signature);
        $images = ['signature' => (string) Storage::disk($certificate['file']->disk)->get($certificate['file']->path)];
        $out = $this->renderer->appendPage($bytes, view('pdf.certificate', ['c' => $certificate['view']])->render(), $images, $document->number);
        $fingerprint = hash('sha256', $signature->id.'|'.$original->sha256.'|'.DocumentSheet::LAYOUT_VERSION);

        return DB::transaction(function () use ($signature, $document, $original, $fingerprint, $out) {
            $locked = DocumentSignature::query()->whereKey($signature->id)->lockForUpdate()->firstOrFail();
            if ($locked->signed_pdf_id !== null) {
                return DocumentPdf::query()->findOrFail($locked->signed_pdf_id);
            }
            $pdf = $this->store($document, DocumentPdf::SIGNED, $original->locale, $fingerprint, $out, null);
            $locked->forceFill(['signed_pdf_id' => $pdf->id])->save();
            $signature->setRawAttributes($locked->getAttributes(), true);

            return $pdf;
        });
    }

    /**
     * The document's file name for download, e.g. «Q-2026-000012-ar.pdf».
     */
    public static function fileName(AgreementDocument $document, string $locale, bool $signed = false): string
    {
        return $document->number.'-'.$locale.($signed ? '-signed' : '').'.pdf';
    }

    public static function locale(?string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? (string) $locale : 'ar';
    }

    public static function latest(AgreementDocument $document, string $variant, string $locale): ?DocumentPdf
    {
        return DocumentPdf::query()->with('attachment')->where('agreement_document_id', $document->id)
            ->where('variant', $variant)->where('locale', $locale)->latest('id')->first();
    }

    private static function html(DocumentSheet $sheet): string
    {
        return view('pdf.document', ['v' => $sheet->view])->render();
    }

    private function store(AgreementDocument $document, string $variant, string $locale, string $fingerprint, string $bytes, ?User $actor): DocumentPdf
    {
        return DB::transaction(function () use ($document, $variant, $locale, $fingerprint, $bytes, $actor) {
            // One writer per document: a concurrent request that generated the same PDF reuses its row.
            AgreementDocument::query()->whereKey($document->id)->lockForUpdate()->first(['id']);
            $existing = DocumentPdf::query()->with('attachment')->where('agreement_document_id', $document->id)
                ->where('variant', $variant)->where('locale', $locale)->where('fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                return $existing;
            }

            $signed = $variant === DocumentPdf::SIGNED;
            $file = $this->files->storeGenerated($document, $bytes, self::fileName($document, $locale, $signed), 'pdf', 'application/pdf',
                $signed ? self::SIGNED_PURPOSE : self::PURPOSE, $actor);

            $pdf = DocumentPdf::query()->create([
                'agreement_document_id' => $document->id,
                'variant' => $variant,
                'locale' => $locale,
                'fingerprint' => $fingerprint,
                'layout_version' => DocumentSheet::LAYOUT_VERSION,
                'attachment_id' => $file->id,
                'sha256' => $file->sha256,
            ]);
            $pdf->setRelation('attachment', $file);

            return $pdf;
        });
    }

    /**
     * @return array{view: array<string, mixed>, file: Attachment}
     */
    private function certificate(DocumentSignature $signature): array
    {
        $document = $signature->document;
        $order = $document->order;
        $customer = $signature->customer;
        $facility = Facility::current();
        $locale = $signature->locale;
        $sheet = DocumentSheet::build($document, $locale);
        $file = Attachment::query()->findOrFail($signature->signature_attachment_id);
        $timezone = $facility->timezone ?? config('app.timezone');

        return ['file' => $file, 'view' => [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'brand' => $sheet->view['brand'],
            'facility' => $sheet->view['facility']['name'],
            'kind' => $document->kind->value,
            'number' => $document->number,
            'order' => $order->number,
            'signer_name' => $signature->signer_name,
            'customer' => $customer->name,
            'mobile' => CustomerOtp::maskMobile($customer->mobile),
            'signed_at' => $signature->signed_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s').' ('.$timezone.')',
            'otp_channel' => $signature->otp_channel,
            'otp_reference' => 'OTP-'.$signature->otp_challenge_id,
            'ip' => $signature->ip,
            'user_agent' => $signature->user_agent,
            'content_hash' => $signature->content_hash,
            'pdf_sha256' => $signature->pdf_sha256,
            'reference' => 'SIG-'.$signature->id.' / '.$signature->customer_decision_id,
            'consent_text' => __('esign.consent.text', [], $locale),
        ]];
    }
}
