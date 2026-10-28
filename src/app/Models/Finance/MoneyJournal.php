<?php

namespace App\Models\Finance;

use App\Enums\JournalKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One effect of a payment (ADR-0014): its signed entries say where the money is (an order's credit or a due). Written
 * once; a correction is a reversal journal (`reverses_journal_id`, unique — reversed at most once).
 *
 * @property int $id
 * @property JournalKind $kind
 * @property int $payment_id
 * @property int|null $reverses_journal_id
 * @property string|null $reason
 * @property int|null $actor_user_id
 * @property string $source
 * @property CarbonInterface $created_at
 * @property-read Payment $payment
 */
class MoneyJournal extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Money journals are append-only.'));
        static::deleting(fn () => throw new LogicException('Money journals are append-only.'));
    }

    /**
     * @return HasMany<MoneyEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(MoneyEntry::class, 'journal_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    protected function casts(): array
    {
        return ['kind' => JournalKind::class, 'created_at' => 'datetime'];
    }
}
