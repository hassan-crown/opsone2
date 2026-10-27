<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An in-app notification. `data` holds translation keys and non-sensitive parameters; the target is only a
 * pointer — opening it re-checks the Gate on the record (notification links never grant access).
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property array{title: string, body?: string|null, params?: array<string, scalar>} $data
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $dedupe_key
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class UserNotification extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
