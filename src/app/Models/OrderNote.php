<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A note on an order (ADR-0042: added by an automation rule; `source` says who wrote it). Append-only.
 *
 * @property int $id
 * @property int $order_id
 * @property string $body
 * @property string $source
 * @property string|null $rule_key
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property-read User|null $author
 */
class OrderNote extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order notes are append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'created_at' => 'datetime'];
    }
}
