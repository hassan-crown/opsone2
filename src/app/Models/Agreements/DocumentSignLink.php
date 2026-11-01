<?php

namespace App\Models\Agreements;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signing link staff sent to the customer (ADR-0029). Only the sha256 of its random token is kept; it opens the
 * document in the customer's own workspace (sign-in still required), expires, and stops working once used, revoked,
 * or when the document no longer awaits the customer.
 *
 * @property int $id
 * @property int $agreement_document_id
 * @property string $token_hash
 * @property int $created_by
 * @property string $channel
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $used_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface $created_at
 * @property-read AgreementDocument $document
 * @property-read User $creator
 */
class DocumentSignLink extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    /**
     * @return BelongsTo<AgreementDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AgreementDocument::class, 'agreement_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLive(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
