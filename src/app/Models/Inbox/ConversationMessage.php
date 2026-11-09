<?php

namespace App\Models\Inbox;

use App\Models\Attachment;
use App\Models\User;
use App\Whatsapp\MessageStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inbound or outbound WhatsApp message of a conversation. The text is encrypted at rest and hidden from
 * serialization; the presenter returns it only to staff holding P-108 over the conversation.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $direction in|out
 * @property string $type
 * @property string|null $provider_message_id
 * @property string|null $idempotency_key
 * @property string|null $body
 * @property string|null $template
 * @property string|null $template_language
 * @property string $status received|queued|sent|delivered|read|failed
 * @property string|null $error
 * @property string|null $media_provider_id
 * @property string|null $media_mime
 * @property string|null $media_status
 * @property int|null $attachment_id
 * @property int|null $sent_by
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $failed_at
 * @property string|null $ai_intent AI-4 classification of an inbound message (InboxIntent), set once
 * @property bool $ai_suspicious AI-4: the message tried to steer the model, or is abusive
 * @property int|null $ai_suggestion_id the AI reply suggestion an outbound message came from
 * @property-read Conversation $conversation
 * @property-read Attachment|null $attachment
 * @property-read User|null $sender
 */
#[Hidden(['body'])]
class ConversationMessage extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public const RECEIVED = 'received';

    public const MEDIA_PENDING = 'pending';

    public const MEDIA_STORED = 'stored';

    public const MEDIA_REJECTED = 'rejected';

    public const MEDIA_FAILED = 'failed';

    /** Message types that carry a file. */
    public const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'sticker'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'ai_suspicious' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * Moves an outbound status forward only (a replayed or late webhook never regresses it), like CustomerMessage.
     */
    public function advance(MessageStatus $to, ?string $error = null): bool
    {
        if ($this->direction !== self::OUT) {
            return false;
        }
        $current = MessageStatus::tryFrom($this->status) ?? MessageStatus::Queued;
        $moves = $to === MessageStatus::Failed
            ? ! in_array($current, [MessageStatus::Delivered, MessageStatus::Read, MessageStatus::Failed], true)
            : $to->rank() > $current->rank();
        if (! $moves) {
            return false;
        }

        $stamp = match ($to) {
            MessageStatus::Sent => 'sent_at',
            MessageStatus::Delivered => 'delivered_at',
            MessageStatus::Read => 'read_at',
            MessageStatus::Failed => 'failed_at',
            MessageStatus::Queued => null,
        };
        $this->status = $to->value;
        if ($stamp !== null) {
            $this->{$stamp} = now();
        }
        if ($error !== null) {
            $this->error = mb_substr($error, 0, 64);
        }
        $this->save();

        return true;
    }
}
