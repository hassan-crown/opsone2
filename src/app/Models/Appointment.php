<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\AppointmentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An appointment of an order with actual members as resources (M-16, SHR-03/04). `pending_change` is the separate
 * proposal of «مؤكد مع مقترح تغيير»; the original time stays until it is approved.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $task_id
 * @property string $kind
 * @property AppointmentStatus $status
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $confirmation_method
 * @property int|null $confirmed_by
 * @property CarbonInterface|null $confirmed_at
 * @property string|null $conflict_reason
 * @property bool $conflicted
 * @property array{starts_at: string, ends_at: string, reason: string, requested_by: int, requested_at: string}|null $pending_change
 * @property string|null $outcome
 * @property bool $no_show
 * @property string|null $cancel_reason
 * @property int|null $created_by
 * @property int $lock_version
 * @property-read Order $order
 */
class Appointment extends Model implements ScopedRecord
{
    public const KINDS = ['measurement', 'installation', 'delivery', 'visit'];

    public const CONFIRMATION_METHODS = ['phone', 'in_person', 'message', 'other'];

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'appointment_members');
    }

    /**
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_values(array_map(intval(...), $this->members()->orderBy('users.id')->pluck('users.id')->all()));
    }

    /**
     * Q-33 (a): the address opens the device's maps app; no embedded map or API key.
     */
    public function mapsUrl(): string
    {
        $query = $this->latitude !== null && $this->longitude !== null
            ? ((float) $this->latitude).','.((float) $this->longitude)
            : $this->address;

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }

    public function accessBranchIds(): array
    {
        return [$this->order->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return $this->memberIds();
    }

    public function accessOwnerId(): ?int
    {
        return $this->created_by;
    }

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'conflicted' => 'boolean',
            'no_show' => 'boolean',
            'pending_change' => 'array',
            'lock_version' => 'integer',
        ];
    }
}
