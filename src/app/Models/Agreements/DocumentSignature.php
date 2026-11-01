<?php

namespace App\Models\Agreements;

use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The customer's electronic signature of a quote or contract (ADR-0029): who signed (typed name, drawn signature,
 * explicit consent), when, from where (IP, user agent), what (content hash and the hash of the exact PDF shown) and
 * the step-up code that confirmed it. Written once with its customer decision; only the signed PDF is attached after.
 *
 * @property int $id
 * @property int $agreement_document_id
 * @property int $order_id
 * @property int $customer_id
 * @property int $customer_decision_id
 * @property string $signer_name
 * @property string $locale
 * @property CarbonInterface $signed_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $content_hash
 * @property int $document_pdf_id
 * @property string $pdf_sha256
 * @property int $otp_challenge_id
 * @property string $otp_channel
 * @property string $consent_version
 * @property int $signature_attachment_id
 * @property string $signature_sha256
 * @property int|null $signed_pdf_id
 * @property int|null $sign_link_id
 * @property-read AgreementDocument $document
 * @property-read Customer $customer
 * @property-read DocumentPdf $pdf
 * @property-read DocumentPdf|null $signedPdf
 * @property-read CustomerOtpChallenge $otpChallenge
 */
class DocumentSignature extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $signature) {
            // The signed PDF is attached once, right after the signature; nothing else ever changes.
            if (array_keys($signature->getDirty()) !== ['signed_pdf_id'] || $signature->getRawOriginal('signed_pdf_id') !== null) {
                throw new LogicException('A signature is never changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('A signature is never deleted.'));
    }

    /**
     * @return BelongsTo<AgreementDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AgreementDocument::class, 'agreement_document_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<DocumentPdf, $this>
     */
    public function pdf(): BelongsTo
    {
        return $this->belongsTo(DocumentPdf::class, 'document_pdf_id');
    }

    /**
     * @return BelongsTo<DocumentPdf, $this>
     */
    public function signedPdf(): BelongsTo
    {
        return $this->belongsTo(DocumentPdf::class, 'signed_pdf_id');
    }

    /**
     * @return BelongsTo<CustomerOtpChallenge, $this>
     */
    public function otpChallenge(): BelongsTo
    {
        return $this->belongsTo(CustomerOtpChallenge::class, 'otp_challenge_id');
    }

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
