<?php

namespace App\Models\Store;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The facility's store setup (one row, ADR-0023): closed until enabled with a receiving branch and a handler.
 *
 * @property int $id
 * @property bool $enabled
 * @property int|null $branch_id
 * @property int|null $assignee_id
 * @property int $reservation_minutes
 * @property int $link_hours_default
 * @property int $link_days_max
 * @property int $lock_version
 * @property int|null $updated_by
 * @property-read Branch|null $branch
 * @property-read User|null $assignee
 */
class StoreSettings extends Model
{
    protected $table = 'store_settings';

    protected $guarded = ['id'];

    protected $attributes = ['enabled' => false, 'reservation_minutes' => 30, 'link_hours_default' => 24, 'link_days_max' => 7, 'lock_version' => 1];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'reservation_minutes' => 'integer',
            'link_hours_default' => 'integer',
            'link_days_max' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
