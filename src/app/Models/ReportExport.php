<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $report
 * @property array{from: string, to: string, branch_ids: list<int>, locale: string} $params
 * @property string $status
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $filename
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property int|null $row_count
 * @property bool $truncated
 * @property string|null $error
 * @property Carbon|null $ready_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $purged_at
 */
#[Fillable(['uuid', 'user_id', 'report', 'params', 'status', 'disk', 'path', 'filename', 'size_bytes', 'sha256', 'row_count', 'truncated', 'error', 'ready_at', 'expires_at', 'purged_at'])]
class ReportExport extends Model
{
    public const QUEUED = 'queued';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->purged_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'truncated' => 'boolean',
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }
}
