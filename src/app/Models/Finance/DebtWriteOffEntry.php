<?php

namespace App\Models\Finance;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One signed line of a write-off journal: `receivable` on a due (negative when written off) or `bad_debt` (positive).
 * The entries of one journal always sum to zero.
 *
 * @property int $id
 * @property int $debt_write_off_id
 * @property int $order_id
 * @property int|null $order_due_id
 * @property string $account
 * @property int $amount_minor
 * @property CarbonInterface $created_at
 */
class DebtWriteOffEntry extends Model
{
    public const RECEIVABLE = 'receivable';

    public const BAD_DEBT = 'bad_debt';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Debt write-offs are append-only.'));
        static::deleting(fn () => throw new LogicException('Debt write-offs are append-only.'));
    }

    /** @return BelongsTo<DebtWriteOff, $this> */
    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(DebtWriteOff::class, 'debt_write_off_id');
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
