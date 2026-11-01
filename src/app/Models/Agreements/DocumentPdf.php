<?php

namespace App\Models\Agreements;

use App\Models\Attachment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A generated PDF of a quote or contract (ADR-0029), stored as a private attachment of the document. The fingerprint
 * covers everything the PDF shows and the layout version, so a PDF is generated again only when one of them changes;
 * rows are never changed or deleted (a signed document keeps the exact PDF its customer signed).
 *
 * @property int $id
 * @property int $agreement_document_id
 * @property string $variant
 * @property string $locale
 * @property string $fingerprint
 * @property int $layout_version
 * @property int $attachment_id
 * @property string $sha256
 * @property CarbonInterface $created_at
 * @property-read AgreementDocument $document
 * @property-read Attachment $attachment
 */
class DocumentPdf extends Model
{
    public const ORIGINAL = 'original';

    public const SIGNED = 'signed';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A generated PDF is never changed.'));
        static::deleting(fn () => throw new LogicException('A generated PDF is never deleted.'));
    }

    /**
     * @return BelongsTo<AgreementDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AgreementDocument::class, 'agreement_document_id');
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    protected function casts(): array
    {
        return ['layout_version' => 'integer'];
    }
}
