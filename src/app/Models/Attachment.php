<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A private file owned by a domain record (SHR-10). Created only through App\Files\AttachmentService; never exposed
 * by public URL — downloads go through short-lived signed links that re-check access on the owner.
 *
 * @property int $id
 * @property string $uuid
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string|null $purpose
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $extension
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $uploaded_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Attachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['disk', 'path'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }
}
