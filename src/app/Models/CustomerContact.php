<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A contact person of an organization customer. Not an account, not a seat, no access by itself (COM-02).
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string $mobile
 * @property string|null $job_title
 * @property string|null $email
 * @property string|null $note
 * @property bool $is_primary
 * @property int|null $primary_of
 * @property Carbon|null $removed_at
 * @property-read Customer $customer
 */
#[Fillable(['customer_id', 'name', 'mobile', 'job_title', 'email', 'note', 'is_primary', 'primary_of', 'removed_at'])]
class CustomerContact extends Model
{
    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'removed_at' => 'datetime'];
    }
}
