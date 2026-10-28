<?php

namespace App\Models\Procurement;

use App\Access\Contracts\ScopedRecord;
use App\Enums\PurchaseStatus;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * M-13 (FUL-05..07): a purchase order to one supplier for one branch, with a gapless number PO-YYYY-000123. Its
 * scope is its branch. Amounts follow the facility VAT policy captured on the order; they are visible only with P-82.
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property int $branch_id
 * @property int|null $location_id
 * @property PurchaseStatus $status
 * @property CarbonInterface|null $expected_on
 * @property int|null $responsible_id
 * @property string|null $notes
 * @property int $vat_rate_bp
 * @property bool $prices_include_vat
 * @property int $net_minor
 * @property int $vat_minor
 * @property int $gross_minor
 * @property CarbonInterface|null $ordered_at
 * @property int|null $ordered_by
 * @property string|null $cancel_reason
 * @property CarbonInterface|null $cancelled_at
 * @property int|null $cancelled_by
 * @property int $lock_version
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property-read Supplier $supplier
 * @property-read Branch $branch
 * @property-read User|null $responsible
 * @property-read Collection<int, PurchaseOrderLine> $lines
 */
class PurchaseOrder extends Model implements ScopedRecord
{
    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $po) {
            $confirmed = $po->getOriginal('status') !== PurchaseStatus::Draft;
            if ($po->isDirty('number') || ($confirmed && ($po->isDirty('supplier_id') || $po->isDirty('branch_id')))) {
                throw new LogicException('A purchase order keeps its number, supplier and branch once confirmed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Purchase orders are never deleted.'));
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<PurchaseReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)->orderByDesc('id');
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
        return $this->responsible_id === null ? [] : [$this->responsible_id];
    }

    public function accessOwnerId(): ?int
    {
        return $this->created_by;
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'expected_on' => 'date',
            'prices_include_vat' => 'boolean',
            'vat_rate_bp' => 'integer',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'gross_minor' => 'integer',
            'ordered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
