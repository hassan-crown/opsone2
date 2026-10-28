<?php

namespace App\Models\Finance;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A signed amount of a payment on an order: on a due (allocated) or without one (the order's credit). Append-only.
 *
 * @property int $id
 * @property int $journal_id
 * @property int $payment_id
 * @property int $order_id
 * @property int|null $order_due_id
 * @property int $amount_minor
 * @property CarbonInterface $created_at
 */
class MoneyEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Money entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Money entries are append-only.'));
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
