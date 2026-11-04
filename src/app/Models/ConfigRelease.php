<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An immutable, numbered configuration release (ADR-0040): the snapshot of the business rules the application reads
 * from the moment it is published. Orders pin the release that was current when they were created. A rollback
 * publishes a copy of an older release (`restored_from_id`); no release is ever changed or deleted.
 *
 * @property int $id
 * @property int $number
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_hash
 * @property int|null $flow_version_id
 * @property string|null $notes
 * @property int|null $restored_from_id
 * @property int|null $published_by
 * @property CarbonInterface $published_at
 * @property-read User|null $publisher
 * @property-read ConfigRelease|null $restoredFrom
 */
class ConfigRelease extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A published configuration release never changes.'));
        static::deleting(fn () => throw new LogicException('A published configuration release is never deleted.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return BelongsTo<ConfigRelease, $this>
     */
    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_id');
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'snapshot' => 'array',
            'flow_version_id' => 'integer',
            'restored_from_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
