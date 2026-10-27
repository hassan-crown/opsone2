<?php

namespace App\Models;

use App\Enums\SetupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The single facility served by this deployment (ADR-0002). Exactly one row.
 *
 * @property int $id
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $address
 * @property string $default_locale
 * @property string $currency
 * @property Carbon|null $currency_locked_at
 * @property string $timezone
 * @property int|null $owner_membership_id
 * @property SetupStatus $setup_status
 * @property array<string, mixed>|null $setup_steps
 * @property Carbon|null $setup_completed_at
 * @property-read Membership|null $owner
 */
#[Table('facility')]
#[Fillable(['name_ar', 'name_en', 'contact_email', 'contact_phone', 'address', 'default_locale', 'currency', 'timezone', 'setup_steps'])]
class Facility extends Model
{
    /**
     * The facility row, or null before provisioning seeded it.
     */
    public static function current(): ?self
    {
        return self::query()->orderBy('id')->first();
    }

    public static function currentOrFail(): self
    {
        return self::current() ?? throw new RuntimeException('The facility profile has not been provisioned.');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'owner_membership_id');
    }

    protected function casts(): array
    {
        return [
            'setup_status' => SetupStatus::class,
            'setup_steps' => 'array',
            'currency_locked_at' => 'datetime',
            'setup_completed_at' => 'datetime',
        ];
    }
}
