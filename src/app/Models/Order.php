<?php

namespace App\Models;

use App\Access\Contracts\LicensedRecord;
use App\Access\Contracts\ScopedRecord;
use App\Access\Contracts\StatefulRecord;
use App\Access\Permission;
use App\Enums\CustomOrderStatus;
use App\Enums\OrderKind;
use App\Enums\ReadyOrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * The central order record (COM-08..12). Custom and ready orders are separate rows; `status` holds an M-04 or M-05
 * state depending on `kind` (see state()). Writes go through App\Actions\Orders, which check `lock_version`.
 *
 * @property int $id
 * @property string $number
 * @property OrderKind $kind
 * @property string $status
 * @property int $customer_id
 * @property int|null $contact_id
 * @property int $branch_id
 * @property int|null $assignee_id
 * @property int|null $created_by
 * @property int|null $purchase_reference_id
 * @property string|null $need_summary
 * @property bool $needs_measurement
 * @property bool $needs_design
 * @property string|null $invoice_type
 * @property int|null $total_minor
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Customer $customer
 * @property-read CustomerContact|null $contact
 * @property-read Branch $branch
 * @property-read User|null $assignee
 * @property-read PurchaseReference|null $purchaseReference
 */
class Order extends Model implements LicensedRecord, ScopedRecord, StatefulRecord
{
    public const INVOICE_TYPES = ['tax', 'simplified'];

    protected $guarded = ['id', 'number', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    /** Identity of the order, fixed once written: its number, kind (COM-10: no kind change), customer and creator. */
    private const IMMUTABLE = ['number', 'kind', 'customer_id', 'created_by'];

    protected static function booted(): void
    {
        static::updating(function (self $order) {
            if (array_intersect(array_keys($order->getDirty()), self::IMMUTABLE) !== []) {
                throw new LogicException('An order keeps its number, kind, customer and creator.');
            }
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<CustomerContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'contact_id');
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

    /**
     * @return BelongsTo<PurchaseReference, $this>
     */
    public function purchaseReference(): BelongsTo
    {
        return $this->belongsTo(PurchaseReference::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function activeLines(): HasMany
    {
        return $this->lines()->whereNull('removed_at');
    }

    public function state(): CustomOrderStatus|ReadyOrderStatus
    {
        return $this->kind === OrderKind::Custom
            ? CustomOrderStatus::from($this->status)
            : ReadyOrderStatus::from($this->status);
    }

    public function isEditable(): bool
    {
        return $this->state()->isEditable();
    }

    public function isReady(): bool
    {
        return $this->kind === OrderKind::Ready;
    }

    /**
     * A copy placed in another branch, to ask the Gate about the target of a transfer or a new order's branch.
     */
    public function inBranch(int $branchId): self
    {
        $copy = $this->replicate();
        $copy->branch_id = $branchId;
        $copy->exists = $this->exists;

        return $copy;
    }

    public function accessBranchIds(): array
    {
        return [$this->branch_id];
    }

    /**
     * @return HasOne<OrderWorkflow, $this>
     */
    public function workflow(): HasOne
    {
        return $this->hasOne(OrderWorkflow::class);
    }

    /**
     * @return HasMany<OrderStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(OrderStage::class)->orderBy('position');
    }

    /**
     * The department following the order's active main stage (P5): a department supervisor's scope covers the order
     * while its current stage is with that department. No workflow or no department follow-up means none.
     */
    public function accessDepartmentId(): ?int
    {
        if (! $this->exists || $this->kind !== OrderKind::Custom) {
            return null;
        }

        $department = OrderStage::query()
            ->whereKey(OrderWorkflow::query()->where('order_id', $this->id)->select('active_stage_id'))
            ->value('follow_up_department_id');

        return $department === null ? null : (int) $department;
    }

    public function accessAssigneeIds(): array
    {
        return $this->assignee_id === null ? [] : [(int) $this->assignee_id];
    }

    public function accessOwnerId(): ?int
    {
        return $this->created_by === null ? null : (int) $this->created_by;
    }

    /**
     * Record-state step of the Gate: the matrix's conditions on P-15/17/18/19/29 for the current M-04/M-05 state.
     */
    public function permitsAction(Permission $permission): bool
    {
        if (! $this->exists) {
            return true;
        }

        return match ($permission) {
            // On an existing order P-15 only confirms a ready draft (M-05-T01).
            Permission::P15 => $this->isReady() && $this->state() === ReadyOrderStatus::Draft,
            Permission::P17, Permission::P29 => $this->isEditable(),
            Permission::P18, Permission::P19 => ! $this->state()->isFinal(),
            default => true,
        };
    }

    /**
     * The ready part of P-15/P-17/P-29 needs `ready_goods`; viewing, cancelling and transferring an existing ready
     * order stay open so it can be settled after the module is removed (COM-10).
     */
    public function requiredCapability(Permission $permission): ?string
    {
        return $this->isReady() && in_array($permission, [Permission::P15, Permission::P17, Permission::P29], true)
            ? 'ready_goods'
            : null;
    }

    protected function casts(): array
    {
        return [
            'kind' => OrderKind::class,
            'needs_measurement' => 'boolean',
            'needs_design' => 'boolean',
            'total_minor' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
