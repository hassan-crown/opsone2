<?php

namespace App\Models\Installation;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A before/after photo of an installation visit: the private file is the attachment, this row says which phase it
 * documents and carries the device's upload id so a retried upload never adds a copy.
 *
 * @property int $id
 * @property int $installation_visit_id
 * @property int $attachment_id
 * @property string $phase
 * @property string $client_uuid
 * @property int|null $uploaded_by
 * @property-read Attachment $attachment
 */
class InstallationPhoto extends Model
{
    public const BEFORE = 'before';

    public const AFTER = 'after';

    public const PHASES = [self::BEFORE, self::AFTER];

    protected $guarded = ['id'];

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /** @return BelongsTo<InstallationVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(InstallationVisit::class, 'installation_visit_id');
    }
}
