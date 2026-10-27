<?php

namespace App\Models;

use App\Enums\SupportGrantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OpsOne support access to this facility (M-38, SHR-14): requested by support, granted, rejected or revoked by
 * the owner (P-79), expiring on its own. Only an `active` grant whose `expires_at` is still ahead is live.
 *
 * @property int $id
 * @property string $uuid
 * @property int $support_user_id
 * @property int|null $support_request_id
 * @property string|null $ticket
 * @property string $reason
 * @property list<string> $requested_areas
 * @property int $requested_hours
 * @property list<string>|null $areas
 * @property int|null $hours
 * @property SupportGrantStatus $status
 * @property Carbon $request_expires_at
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $ended_at
 * @property-read User $supportUser
 * @property-read User|null $decidedBy
 * @property-read SupportRequest|null $supportRequest
 */
class SupportGrant extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'status' => 'requested',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isLive(): bool
    {
        return $this->status === SupportGrantStatus::Active && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function supportUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'support_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * @return BelongsTo<SupportRequest, $this>
     */
    public function supportRequest(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class);
    }

    protected function casts(): array
    {
        return [
            'status' => SupportGrantStatus::class,
            'requested_areas' => 'array',
            'areas' => 'array',
            'request_expires_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
