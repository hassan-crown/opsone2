<?php

namespace App\Models\Finance;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * An authorized write-off of what a customer still owes on an order (P-103, ADR-0035), or its reversal. Append-only:
 * a write-off is undone only by a reversal row that negates its entries, once (`reverses_id` is unique).
 *
 * @property int $id
 * @property string|null $number
 * @property int $order_id
 * @property string $kind
 * @property int|null $reverses_id
 * @property int $amount_minor
 * @property string $reason
 * @property int|null $actor_user_id
 * @property string $source
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read User|null $actor
 */
class DebtWriteOff extends Model implements AttachmentOwner
{
    use OrderScoped;

    public const WRITE_OFF = 'write_off';

    public const REVERSAL = 'reversal';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Debt write-offs are append-only.'));
        static::deleting(fn () => throw new LogicException('Debt write-offs are append-only.'));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasMany<DebtWriteOffEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(DebtWriteOffEntry::class)->orderBy('id');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P37;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P103;
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
