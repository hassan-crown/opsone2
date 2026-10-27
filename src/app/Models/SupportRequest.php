<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for help from the OpsOne team (SupportRequest screen). Stored here and mailed to OpsOne support through
 * the outbox: `pending` until a support address is configured, `queued` once handed to the outbox.
 *
 * @property int $id
 * @property int $user_id
 * @property string $category
 * @property string $subject
 * @property string $message
 * @property string|null $page_path
 * @property string $locale
 * @property string $status
 * @property-read User $user
 */
#[Fillable(['user_id', 'category', 'subject', 'message', 'page_path', 'locale', 'status'])]
class SupportRequest extends Model
{
    public const CATEGORIES = ['question', 'problem', 'suggestion', 'access'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    /** Reference quoted to OpsOne support and shown on support access requests, e.g. SR-0042. */
    public function reference(): string
    {
        return 'SR-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
