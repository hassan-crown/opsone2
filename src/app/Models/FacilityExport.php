<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A full facility data export (Q-53 a): queued → running → ready (downloadable until `expires_at`) | failed → expired.
 *
 * @property int $id
 * @property string $uuid
 * @property int $requested_by
 * @property string $status
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property array<string, int>|null $summary
 * @property string|null $failure
 * @property Carbon|null $ready_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read User $requester
 */
class FacilityExport extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function downloadable(): bool
    {
        return $this->status === self::READY && $this->path !== null && $this->expires_at?->isFuture() === true;
    }

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
