<?php

namespace App\Models\Installation;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remark recorded on site (ADR-0036), on the same model as the delivery remarks (ADR-0032/0035): every remark is
 * `open` with a follow-up person and keeps the order from closing until it is resolved on its own.
 *
 * @property int $id
 * @property int $installation_visit_id
 * @property int $order_id
 * @property string $kind
 * @property string $description
 * @property string $status
 * @property int|null $follow_up_user_id
 * @property int|null $created_by
 * @property string|null $resolution
 * @property int|null $resolved_by
 * @property CarbonInterface|null $resolved_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read InstallationVisit $visit
 * @property-read User|null $followUp
 */
class InstallationRemark extends Model
{
    public const KINDS = ['missing_part', 'damage', 'extra_work', 'other'];

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return BelongsTo<InstallationVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(InstallationVisit::class, 'installation_visit_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function followUp(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follow_up_user_id');
    }

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'lock_version' => 'integer'];
    }
}
