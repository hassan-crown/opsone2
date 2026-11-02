<?php

namespace App\Agreements\Signing;

use App\Access\Exceptions\InvalidTransition;
use App\Agreements\CustomerActor;
use App\Agreements\CustomerDecisions;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentStatus;
use App\Files\AttachmentService;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\Agreements\DocumentSignature;
use App\Models\Agreements\DocumentSignLink;
use App\Models\Customer;
use App\Models\Order;
use App\Portal\CustomerOtp;
use App\Portal\PortalSession;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The customer signs a quote or contract from their own account (ADR-0029): after reading the exact PDF, with a typed
 * name, a drawn signature, an explicit consent and a fresh step-up code. The signature is the customer's approval of
 * the document through the existing M-08-T02 path (CustomerDecisions → DocumentApproval: same state machine, same
 * content-hash check, same agreement activation for a quote), written in the decision's transaction with the
 * signature record and the audit entry. The signed PDF (original pages + certificate) is generated right after; if
 * that fails it is generated on the next download, the signature stands.
 */
final class ESignature
{
    /** Version of the consent wording the customer accepts (esign.consent.text). */
    public const CONSENT_VERSION = '2026-11';

    public const SIGNATURE_PURPOSE = 'esign_signature';

    private const MAX_SIGNATURE_BYTES = 300_000;

    public function __construct(
        private readonly CustomerOtp $otp,
        private readonly CustomerDecisions $decisions,
        private readonly DocumentPdfs $pdfs,
        private readonly AttachmentService $files,
    ) {}

    public static function subject(AgreementDocument $document): string
    {
        return 'document:'.$document->id;
    }

    /**
     * Can the customer sign this (already scoped) document now?
     */
    public static function signable(AgreementDocument $document): bool
    {
        return DocumentSheet::supports($document)
            && $document->status === DocumentStatus::AwaitingCustomer
            && ($document->valid_until === null || ! $document->valid_until->copy()->endOfDay()->isPast())
            && ! DocumentSignature::query()->where('agreement_document_id', $document->id)->exists();
    }

    /**
     * Sends the step-up code for this document. Returns the masked destination, or null when the account has none.
     */
    public function requestCode(Customer $customer, AgreementDocument $document, ?string $ip): ?string
    {
        self::ensureOwnSignable($customer, $document);

        return $this->otp->issueStepUp($customer, self::subject($document), $ip);
    }

    /**
     * @param  array{signer_name: string, signature: string, consent: bool, code: string, content_hash: string, pdf_sha256: string, locale: string}  $input
     */
    public function sign(Customer $customer, AgreementDocument $document, array $input, ?string $ip, ?string $userAgent, ?DocumentSignLink $link = null): DocumentSignature
    {
        self::ensureOwnSignable($customer, $document);
        if (! $input['consent']) {
            throw ValidationException::withMessages(['consent' => __('esign.error.consent')]);
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $input['signer_name']));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['signer_name' => __('esign.error.name')]);
        }
        $png = self::signatureImage($input['signature']);
        $locale = DocumentPdfs::locale($input['locale']);

        // The customer signs the exact PDF they were shown: a regenerated PDF (new branding, new schedule) is refused.
        $pdf = $this->pdfs->current($document, $locale);
        if (! hash_equals($pdf->sha256, $input['pdf_sha256'])) {
            throw ValidationException::withMessages(['pdf_sha256' => __('esign.error.pdf_changed')]);
        }

        $challenge = $this->otp->verifyStepUp($customer, self::subject($document), $input['code'], $ip);
        if ($challenge === null) {
            throw ValidationException::withMessages(['code' => __('esign.error.code')]);
        }

        $signature = null;
        try {
            $this->decisions->approveDocument(new CustomerActor($customer->id, $customer->mobile), $document, $input['content_hash'], CustomerDecision::METHOD_SIGNATURE,
                function (Order $order, AgreementDocument $locked, CustomerDecision $decision) use (&$signature, $customer, $name, $locale, $ip, $userAgent, $pdf, $challenge, $png, $link) {
                    $image = $this->files->storeGenerated($locked, $png, 'signature-'.$locked->number, 'png', 'image/png', self::SIGNATURE_PURPOSE);
                    $liveLink = $link !== null && $link->agreement_document_id === $locked->id
                        ? DocumentSignLink::query()->whereKey($link->id)->lockForUpdate()->first() : null;
                    $liveLink = $liveLink !== null && $liveLink->isLive() ? $liveLink : null;

                    $signature = DocumentSignature::query()->create([
                        'agreement_document_id' => $locked->id,
                        'order_id' => $order->id,
                        'customer_id' => $customer->id,
                        'customer_decision_id' => $decision->id,
                        'signer_name' => $name,
                        'locale' => $locale,
                        'signed_at' => $decision->decided_at,
                        'ip' => $ip,
                        'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                        'content_hash' => (string) $locked->content_hash,
                        'document_pdf_id' => $pdf->id,
                        'pdf_sha256' => $pdf->sha256,
                        'otp_challenge_id' => $challenge->id,
                        'otp_channel' => $challenge->channel,
                        'consent_version' => self::CONSENT_VERSION,
                        'signature_attachment_id' => $image->id,
                        'signature_sha256' => $image->sha256,
                        'sign_link_id' => $liveLink?->id,
                    ]);
                    $liveLink?->forceFill(['used_at' => now()])->save();
                    // Every other link of the document stops working: it is decided.
                    DocumentSignLink::query()->where('agreement_document_id', $locked->id)->whereNull('used_at')->whereNull('revoked_at')
                        ->update(['revoked_at' => now()]);

                    Audit::record(AuditAction::DocumentSigned, $order, null, after: [
                        'document' => $locked->number, 'signature' => $signature->id, 'pdf_sha256' => $pdf->sha256,
                        'content_hash' => $locked->content_hash, 'otp' => 'OTP-'.$challenge->id, 'link' => $liveLink !== null,
                        ...($challenge->getAttribute('demo_used') === true ? ['demo_code' => true] : []),
                    ], source: PortalSession::SOURCE, label: $order->number);
                });
        } catch (InvalidTransition $e) {
            throw ValidationException::withMessages(['transition' => __('esign.error.'.self::reason($e->condition))]);
        }

        /** @var DocumentSignature $signature */
        try {
            $this->pdfs->signed($signature);
        } catch (Throwable $e) {
            report($e);
        }

        return $signature->refresh();
    }

    private static function ensureOwnSignable(Customer $customer, AgreementDocument $document): void
    {
        // The portal already scoped the document to the customer; a second check costs nothing.
        if ($document->order->customer_id !== $customer->id) {
            abort(404);
        }
        if (DocumentSignature::query()->where('agreement_document_id', $document->id)->exists()) {
            throw ValidationException::withMessages(['transition' => __('esign.error.already_signed')]);
        }
        if (! self::signable($document)) {
            throw ValidationException::withMessages(['transition' => __('esign.error.not_signable')]);
        }
    }

    /**
     * The drawn signature as PNG bytes from its data URL, or a validation error.
     */
    private static function signatureImage(string $dataUrl): string
    {
        $error = ValidationException::withMessages(['signature' => __('esign.error.signature')]);
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw $error;
        }
        $bytes = base64_decode(substr($dataUrl, 22), true);
        if ($bytes === false || strlen($bytes) < 200 || strlen($bytes) > self::MAX_SIGNATURE_BYTES) {
            throw $error;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] < 100 || $info[1] < 40 || $info[0] > 2000 || $info[1] > 1000) {
            throw $error;
        }

        return $bytes;
    }

    private static function reason(string $condition): string
    {
        return match ($condition) {
            'already_approved' => 'already_signed',
            'content_changed', 'expired', 'agreement_exists' => $condition,
            default => 'not_signable',
        };
    }
}
