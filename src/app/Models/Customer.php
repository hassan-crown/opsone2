<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\ContactChannel;
use App\Enums\CustomerKind;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An individual or organization customer (COM-01/02). One record per mobile and per email in the facility.
 *
 * @property int $id
 * @property CustomerKind $kind
 * @property string $name
 * @property string $mobile
 * @property string|null $email
 * @property bool $portal_login_enabled
 * @property string $country
 * @property int $branch_id
 * @property int|null $assignee_id
 * @property int|null $created_by
 * @property ContactChannel|null $preferred_channel
 * @property string|null $internal_notes
 * @property string|null $commercial_registration
 * @property string|null $vat_number
 * @property string|null $office_phone
 * @property-read Branch $branch
 * @property-read User|null $assignee
 */
#[Fillable([
    'kind', 'name', 'mobile', 'email', 'portal_login_enabled', 'country', 'branch_id', 'assignee_id', 'created_by',
    'preferred_channel', 'internal_notes', 'commercial_registration', 'vat_number', 'office_phone',
])]
class Customer extends Model implements ScopedRecord
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /** Fields only holders of P-10 over the record may see (internal notes and business registration data). */
    public const SENSITIVE = ['internal_notes', 'commercial_registration', 'vat_number', 'office_phone'];

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
     * @return HasMany<CustomerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /**
     * @return HasMany<Interest, $this>
     */
    public function interests(): HasMany
    {
        return $this->hasMany(Interest::class, 'customer_id');
    }

    public function isOrganization(): bool
    {
        return $this->kind === CustomerKind::Organization;
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
        return $this->assignee_id === null ? [] : [(int) $this->assignee_id];
    }

    public function accessOwnerId(): ?int
    {
        return $this->created_by === null ? null : (int) $this->created_by;
    }

    protected function casts(): array
    {
        return [
            'kind' => CustomerKind::class,
            'preferred_channel' => ContactChannel::class,
            'portal_login_enabled' => 'boolean',
        ];
    }
}
