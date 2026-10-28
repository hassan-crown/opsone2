<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groups the ready and the custom order of one sale (COM-10). It is neither an order nor an amount: each order keeps
 * its own lines and total, and nothing is summed or collected on the reference.
 *
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property int|null $created_by
 */
class PurchaseReference extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
