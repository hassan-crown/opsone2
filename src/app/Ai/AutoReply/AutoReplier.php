<?php

namespace App\Ai\AutoReply;

use App\Access\TransitionRecorder;
use App\Ai\Inbox\InjectionSignals;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Inbox\ReplySender;
use App\Models\Ai\AiAutoReply;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The optional automatic reply to safe factual questions (AI-6, plan §2 axis 4, Q-92). For one inbound message:
 *
 *   on? (feature switch + ai_plus + at least one intent the owner enabled) → newest text message → never in a group,
 *   a flagged conversation (AI-4 `ai_flagged_at`, the local injection check or this classifier's own flag) or for an
 *   opted-out customer → daily cap → classify (label + confidence only) → confidence threshold → the intent's own
 *   switch and «outside working hours only» → a FIXED template filled from the database (AutoReplyAnswers) → the
 *   inbox send path (ReplySender::automatic: free text inside the 24-hour window, the approved template outside it).
 *
 * The model never writes a word of the reply: it only picks one of five labels. Everything that is not answered is a
 * hand-off to staff, recorded as a note on the conversation (and, if the owner chose it, a fixed «a colleague will
 * answer you» message outside working hours, once a day). Every decision is one `ai_auto_replies` row (one per
 * inbound message, idempotent); every automatic message is audited (`ai.autoreply_sent`).
 */
final class AutoReplier
{
    public const FLAGGED = 'flagged';

    public const GROUP = 'group';

    public const OPTED_OUT = 'opted_out';

    public const CAPPED = 'capped';

    public const CLASSIFIER = 'classifier_unavailable';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const LOW_CONFIDENCE = 'low_confidence';

    public const INTENT_OFF = 'intent_off';

    public const WORKING_HOURS = 'working_hours';

    public const NOT_SENT = 'not_sent';

    /** A follow-up naming the order is read for this long after the reply that asked which order. */
    private const ASKED_ORDER_MINUTES = 60;

    public function __construct(
        private readonly AutoReplyClassifier $classifier,
        private readonly AutoReplySettings $settings,
        private readonly AutoReplyAnswers $answers,
        private readonly ReplySender $sender,
    ) {}

    /** Whether an inbound message should be looked at at all (dispatch check, no model call). */
    public function available(): bool
    {
        return $this->classifier->available() && $this->settings->anyEnabled();
    }

    public function handle(ConversationMessage $message): ?AiAutoReply
    {
        if ($message->direction !== ConversationMessage::IN) {
            return null;
        }
        $done = AiAutoReply::query()->where('inbound_message_id', $message->id)->first();
        if ($done !== null || ! $this->available()) {
            return $done;
        }

        $body = trim((string) $message->body);
        $conversation = Conversation::query()->with('customer')->find($message->conversation_id);
        if ($conversation === null || $body === '' || ! $this->isNewest($message)) {
            return null;
        }

        if ($conversation->is_group) {
            return $this->record($conversation, $message, AiAutoReply::SKIPPED, self::GROUP);
        }
        if ($conversation->ai_flagged_at !== null || $message->ai_suspicious || InjectionSignals::suspicious($body)) {
            $this->flag($conversation, $message);

            return $this->record($conversation, $message, AiAutoReply::SKIPPED, self::FLAGGED);
        }
        if ($conversation->customer?->whatsapp_opt_out_at !== null) {
            return $this->record($conversation, $message, AiAutoReply::SKIPPED, self::OPTED_OUT);
        }
        if ($this->capped($conversation)) {
            return $this->record($conversation, $message, AiAutoReply::HANDOFF, self::CAPPED);
        }

        [$intent, $confidence] = $this->followUp($conversation, $body) ?? [null, null];
        if ($intent === null) {
            $result = $this->classifier->classify($message);
            if ($result === null) {
                return $this->handoff($conversation, $message, self::CLASSIFIER);
            }
            if ($result['suspicious']) {
                $this->flag($conversation, $message);

                return $this->record($conversation, $message, AiAutoReply::SKIPPED, self::FLAGGED);
            }
            $intent = $result['intent'];
            $confidence = $result['confidence'];
            if ($intent === null) {
                return $this->handoff($conversation, $message, self::OUT_OF_SCOPE, confidence: $confidence);
            }
            if ($confidence < (float) config('ai.auto_reply.min_confidence', 0.8)) {
                return $this->handoff($conversation, $message, self::LOW_CONFIDENCE, $intent, $confidence);
            }
        }

        $choice = $this->settings->intent($intent);
        if (! $choice['enabled']) {
            return $this->handoff($conversation, $message, self::INTENT_OFF, $intent, $confidence);
        }
        if ($choice['mode'] === AutoReplySettings::OUTSIDE_HOURS && WorkingHours::isOpen(now())) {
            return $this->record($conversation, $message, AiAutoReply::SKIPPED, self::WORKING_HOURS, $intent, $confidence);
        }

        $answer = $this->answers->answer($intent, $conversation, $body);
        if (isset($answer['handoff'])) {
            return $this->handoff($conversation, $message, $answer['handoff'], $intent, $confidence);
        }

        return $this->send($conversation, $message, $answer['text'], AiAutoReply::SENT, $answer['reason'], $intent, $confidence, $answer['order_id']);
    }

    /**
     * Messages that reached the cap today (rolling 24 hours): every automatic message counts, hand-off notes too.
     */
    public function capped(Conversation $conversation): bool
    {
        return AiAutoReply::query()->where('conversation_id', $conversation->id)->whereNotNull('reply_message_id')
            ->where('created_at', '>=', now()->subDay())->count() >= max(0, (int) config('ai.auto_reply.daily_cap', 3));
    }

    /**
     * The answer to «which order?»: the previous automatic reply asked it, recently, and this message names exactly one
     * of the customer's own open orders. Same intent, no new classification.
     *
     * @return array{0: AutoReplyIntent, 1: float}|null
     */
    private function followUp(Conversation $conversation, string $body): ?array
    {
        $last = AiAutoReply::query()->where('conversation_id', $conversation->id)->latest('id')->first();
        if ($last === null || $last->outcome !== AiAutoReply::SENT || $last->reason !== AutoReplyAnswers::ASKED_ORDER
            || $last->created_at->lt(now()->subMinutes(self::ASKED_ORDER_MINUTES))) {
            return null;
        }
        $intent = AutoReplyIntent::tryFrom((string) $last->intent);
        $customer = AutoReplyAnswers::matchedCustomer($conversation);
        if ($intent === null || $customer === null || AutoReplyAnswers::named(AutoReplyAnswers::openOrders($customer), $body) === null) {
            return null;
        }

        return [$intent, 1.0];
    }

    private function handoff(Conversation $conversation, ConversationMessage $message, string $reason, ?AutoReplyIntent $intent = null, ?float $confidence = null): AiAutoReply
    {
        $noteToday = AiAutoReply::query()->where('conversation_id', $conversation->id)->where('outcome', AiAutoReply::HANDOFF)
            ->whereNotNull('reply_message_id')->where('created_at', '>=', now()->subDay())->exists();
        if (! $this->settings->all()['handoff_note'] || $noteToday || WorkingHours::isOpen(now())) {
            return $this->record($conversation, $message, AiAutoReply::HANDOFF, $reason, $intent, $confidence);
        }

        return $this->send($conversation, $message, __('ai.autoreply.reply.handoff', [], AutoReplyAnswers::locale()), AiAutoReply::HANDOFF, $reason, $intent, $confidence);
    }

    /**
     * Queues the fixed text and records the decision in one transaction, the cap re-counted under the conversation
     * lock so two messages processed at once cannot pass it together.
     */
    private function send(Conversation $conversation, ConversationMessage $message, string $text, string $outcome, ?string $reason, ?AutoReplyIntent $intent, ?float $confidence, ?int $orderId = null): AiAutoReply
    {
        try {
            return DB::transaction(function () use ($conversation, $message, $text, $outcome, $reason, $intent, $confidence, $orderId) {
                /** @var Conversation $locked */
                $locked = Conversation::query()->with('customer')->lockForUpdate()->findOrFail($conversation->id);
                $existing = AiAutoReply::query()->where('inbound_message_id', $message->id)->first();
                if ($existing !== null) {
                    return $existing;
                }
                if ($this->capped($locked)) {
                    return $this->create($locked, $message, AiAutoReply::HANDOFF, self::CAPPED, $intent, $confidence);
                }

                // Every automatic message says it is automatic (fixed footer), so the customer never mistakes it for staff.
                $body = $text."\n\n".__('ai.autoreply.reply.footer', [], AutoReplyAnswers::locale());
                $reply = $this->sender->automatic($locked, $body, $this->settings->windowTemplate(), 'autoreply:'.$message->id);
                if ($reply === null) {
                    return $this->create($locked, $message, AiAutoReply::HANDOFF, self::NOT_SENT, $intent, $confidence, $orderId);
                }

                $row = $this->create($locked, $message, $outcome, $reason, $intent, $confidence, $orderId, $reply->id);
                Audit::record(AuditAction::AiAutoreplySent, $locked, null,
                    after: [
                        'outcome' => $outcome, 'reason' => $reason, 'intent' => $intent?->value, 'confidence' => $confidence,
                        'order_id' => $orderId, 'message_id' => $reply->id, 'type' => $reply->type,
                    ],
                    source: TransitionRecorder::SOURCE_SYSTEM,
                    label: 'WhatsApp',
                );

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            return AiAutoReply::query()->where('inbound_message_id', $message->id)->firstOrFail();
        }
    }

    private function record(Conversation $conversation, ConversationMessage $message, string $outcome, string $reason, ?AutoReplyIntent $intent = null, ?float $confidence = null): AiAutoReply
    {
        try {
            return $this->create($conversation, $message, $outcome, $reason, $intent, $confidence);
        } catch (UniqueConstraintViolationException) {
            return AiAutoReply::query()->where('inbound_message_id', $message->id)->firstOrFail();
        }
    }

    private function create(Conversation $conversation, ConversationMessage $message, string $outcome, ?string $reason, ?AutoReplyIntent $intent, ?float $confidence, ?int $orderId = null, ?int $replyId = null): AiAutoReply
    {
        return AiAutoReply::query()->create([
            'conversation_id' => $conversation->id,
            'inbound_message_id' => $message->id,
            'outcome' => $outcome,
            'reason' => $reason,
            'intent' => $intent?->value,
            'confidence' => $confidence === null ? null : round($confidence, 3),
            'order_id' => $orderId,
            'reply_message_id' => $replyId,
        ]);
    }

    private function isNewest(ConversationMessage $message): bool
    {
        $newest = ConversationMessage::query()->where('conversation_id', $message->conversation_id)
            ->where('direction', ConversationMessage::IN)->orderByDesc('occurred_at')->orderByDesc('id')->value('id');

        return $newest === $message->id;
    }

    private function flag(Conversation $conversation, ConversationMessage $message): void
    {
        if (! $message->ai_suspicious) {
            $message->forceFill(['ai_suspicious' => true])->save();
        }
        Conversation::query()->whereKey($conversation->id)->whereNull('ai_flagged_at')->update(['ai_flagged_at' => now()]);
    }
}
