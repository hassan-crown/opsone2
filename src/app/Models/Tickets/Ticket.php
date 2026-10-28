<?php

namespace App\Models\Tickets;

use App\Access\Contracts\ScopedRecord;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer support ticket (SHR-08, M-21). A ScopedRecord by its `branch_id` (the linked order's branch, else the
 * customer's) and its assignee: P-59 is checked against those, never against ids sent by the client.
 *
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property int|null $order_id
 * @property int $branch_id
 * @property int|null $assignee_id
 * @property TicketType $type
 * @property string $subject
 * @property TicketStatus $status
 * @property string|null $resolution
 * @property string|null $client_key
 * @property CarbonInterface|null $last_customer_activity_at
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $closed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Customer $customer
 * @property-read Order|null $order
 * @property-read User|null $assignee
 */
class Ticket extends Model implements ScopedRecord
{
    protected $table = 'tickets';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

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
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
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
            'type' => TicketType::class,
            'status' => TicketStatus::class,
            'last_customer_activity_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
