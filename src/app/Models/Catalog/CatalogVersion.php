<?php

namespace App\Models\Catalog;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One immutable version of a catalog row. Later order lines snapshot a version (its id), so a version is never
 * updated or deleted: a change to the catalog writes a NEW version instead.
 *
 * @property int $id
 * @property string $versionable_type
 * @property int $versionable_id
 * @property int $version
 * @property array<string, mixed> $payload
 * @property int|null $created_by_user_id
 * @property Carbon $created_at
 */
class CatalogVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A catalog version is immutable.'));
        static::deleting(fn () => throw new LogicException('A catalog version is never deleted.'));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer'];
    }
}
