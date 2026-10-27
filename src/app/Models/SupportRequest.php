<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for help from the OpsOne team (SupportRequest screen). Stored here; delivery to OpsOne support
 * goes through the notifications outbox once it exists.
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
