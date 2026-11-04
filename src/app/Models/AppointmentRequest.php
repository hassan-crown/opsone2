<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\AppointmentRequestCloseReason;
use App\Enums\AppointmentRequestStatus;
use App\FieldSales\FieldDispatch;
use App\Models\FieldSales\FieldAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's request for an appointment (ADR-0039). The customer only asks; staff call them and book the appointment
 * through the normal ApptSchedule flow, which links it here. Scoped by branch and assignee for P-54.
 *
 * @property int $id
 * @property string|null $reference
 * @property string $submission_key
 * @property string $source site | portal
 * @property AppointmentRequestStatus $status
 * @property string $kind
 * @property CarbonInterface|null $preferred_from
 * @property CarbonInterface|null $preferred_to
 * @property string|null $time_of_day
 * @property string $name
 * @property string $phone
 * @property string|null $city
 * @property string|null $address
 * @property string|null $note
 * @property int $branch_id
 * @property int|null $interest_id
 * @property int|null $customer_id
 * @property int|null $order_id
 * @property int|null $assignee_id
 * @property int|null $assigned_by
 * @property CarbonInterface|null $assigned_at
 * @property CarbonInterface|null $due_at
 * @property int|null $appointment_id
 * @property int|null $scheduled_by
 * @property CarbonInterface|null $scheduled_at
 * @property AppointmentRequestCloseReason|null $close_reason
 * @property string|null $close_note
 * @property int|null $closed_by
 * @property CarbonInterface|null $closed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Branch $branch
 * @property-read Interest|null $interest
 * @property-read Customer|null $customer
 * @property-read Order|null $order
 * @property-read User|null $assignee
 * @property-read Appointment|null $appointment
 */
class AppointmentRequest extends Model implements ScopedRecord
{
    protected static function booted(): void
    {
        // ADR-0041: a request dispatched to a field rep ends the rep's task once booked or closed, or when it follows
        // someone else.
        static::updated(function (self $request) {
            if ($request->wasChanged('status') && ! $request->status->isOpen()) {
                FieldDispatch::settle(FieldAssignment::REQUEST, $request->id, $request->status === AppointmentRequestStatus::Scheduled ? 'scheduled' : 'closed');
            } elseif ($request->wasChanged('assignee_id')) {
                FieldDispatch::followAssignee(FieldAssignment::REQUEST, $request->id, $request->assignee_id);
            }
        });
    }

    public const SOURCE_SITE = 'site';

    public const SOURCE_PORTAL = 'portal';

    public const TIMES_OF_DAY = ['morning', 'afternoon', 'evening', 'any'];

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Interest, $this>
     */
    public function interest(): BelongsTo
    {
        return $this->belongsTo(Interest::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function accessBranchIds(): array
    {
        return [$this->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return $this->assignee_id === null ? [] : [$this->assignee_id];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'status' => AppointmentRequestStatus::class,
            'close_reason' => AppointmentRequestCloseReason::class,
            'preferred_from' => 'date',
            'preferred_to' => 'date',
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'closed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
