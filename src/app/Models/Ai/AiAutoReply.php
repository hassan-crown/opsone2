<?php

namespace App\Models\Ai;

use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One decision of the automatic reply (AI-6) on one inbound message: sent, handed off to staff, or skipped, and why.
 * Holds no message text (the reply is the conversation message it points to).
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $inbound_message_id
 * @property string $outcome sent|handoff|skipped
 * @property string|null $reason
 * @property string|null $intent
 * @property string|null $confidence
 * @property int|null $order_id
 * @property int|null $reply_message_id
 * @property Carbon $created_at
 */
class AiAutoReply extends Model
{
    public const SENT = 'sent';

    public const HANDOFF = 'handoff';

    public const SKIPPED = 'skipped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confidence' => 'decimal:3'];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<ConversationMessage, $this>
     */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'reply_message_id');
    }
}
