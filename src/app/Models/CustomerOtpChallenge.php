<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in code sent to a customer (ADR-0022). Only the HMAC of the code is kept.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $identifier_hash
 * @property string $purpose
 * @property string $channel
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 * @property string|null $ip
 * @property-read Customer $customer
 */
class CustomerOtpChallenge extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
