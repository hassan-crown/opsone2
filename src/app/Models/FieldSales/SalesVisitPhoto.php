<?php

namespace App\Models\FieldSales;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site photo of a sales visit: the private file is the attachment; the device's upload id keeps a retried upload
 * from adding a copy.
 *
 * @property int $id
 * @property int $sales_visit_id
 * @property int $attachment_id
 * @property string $client_uuid
 * @property int|null $uploaded_by
 * @property-read Attachment $attachment
 */
class SalesVisitPhoto extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
