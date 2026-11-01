<?php

namespace App\Models;

use App\Whatsapp\MessageStatus;
use App\Whatsapp\WhatsAppEvent;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One customer notification and what became of it (queued → sent → delivered → read, or failed), whichever channel
 * carried it. The rendered text is encrypted and only shown to staff who may see the customer's sensitive data.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property int $customer_id
 * @property int|null $order_id
 * @property WhatsAppEvent $event
 * @property string $channel whatsapp|sms
 * @property bool $fell_back
 * @property string|null $template
 * @property string $locale
 * @property MessageStatus $status
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property string|null $body
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property-read Customer $customer
 */
#[Hidden(['body'])]
class CustomerMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event' => WhatsAppEvent::class,
            'status' => MessageStatus::class,
            'fell_back' => 'boolean',
            'body' => 'encrypted',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Moves the status forward only (a replayed or late webhook never regresses it). A `failed` after `sent` is
     * accepted: the provider can report a send as failed later.
     */
    public function advance(MessageStatus $to, ?string $error = null): bool
    {
        $stamp = match ($to) {
            MessageStatus::Sent => 'sent_at',
            MessageStatus::Delivered => 'delivered_at',
            MessageStatus::Read => 'read_at',
            MessageStatus::Failed => 'failed_at',
            MessageStatus::Queued => null,
        };

        $moves = $to === MessageStatus::Failed
            ? ! in_array($this->status, [MessageStatus::Delivered, MessageStatus::Read, MessageStatus::Failed], true)
            : $to->rank() > $this->status->rank();

        if (! $moves) {
            return false;
        }

        $now = Carbon::now();
        $this->status = $to;
        if ($stamp !== null) {
            $this->setAttribute($stamp, $this->getAttribute($stamp) ?? $now);
        }
        // Delivered and read imply sent (a provider can skip a status).
        if (in_array($to, [MessageStatus::Delivered, MessageStatus::Read], true)) {
            $this->sent_at ??= $now;
        }
        if ($to === MessageStatus::Delivered || $to === MessageStatus::Read) {
            $this->error = null;
        } elseif ($error !== null) {
            $this->error = mb_substr($error, 0, 64);
        }
        $this->save();

        return true;
    }
}
