<?php

namespace App\Models\Procurement;

use App\Access\Contracts\ScopedRecord;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier profile (FUL-05, P-45): name, contact and notes, never an accounting payable account. Disabling keeps
 * every purchase made from it; a disabled supplier cannot be chosen on a new purchase order.
 *
 * @property int $id
 * @property Translated $name
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $vat_number
 * @property string|null $supplies
 * @property string|null $notes
 * @property string $status
 * @property CarbonInterface|null $disabled_at
 * @property int $lock_version
 * @property int|null $created_by
 */
class Supplier extends Model implements ScopedRecord
{
    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'status' => self::ACTIVE];

    /**
     * Saudi VAT registration number: 15 digits, first and last 3.
     */
    public static function validVatNumber(string $number): bool
    {
        return preg_match('/^3\d{13}3$/', $number) === 1;
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return [
            'name' => $this->name->toArray(), 'contact_name' => $this->contact_name, 'phone' => $this->phone, 'email' => $this->email,
            'vat_number' => $this->vat_number, 'supplies' => $this->supplies, 'notes' => $this->notes, 'status' => $this->status,
        ];
    }

    public function accessBranchIds(): array
    {
        return [];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return [];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'disabled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
