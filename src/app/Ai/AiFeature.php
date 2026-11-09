<?php

namespace App\Ai;

/**
 * A facility switch (P-02, ADR-0050 §5). Every AI task belongs to one feature; a feature is off unless the facility
 * turns it on, except the help assistant. The capability is what the license must grant (plan §3.7, §4.4):
 * `ai_core` for the basics included in every plan, `ai_plus` for the «OpsOne الذكي» add-on.
 */
enum AiFeature: string
{
    case Help = 'help';
    case FlowSetup = 'flow_setup';
    case Digest = 'digest';
    case QuoteFollowups = 'quote_followups';
    case Inbox = 'inbox';
    case ConversationToOrder = 'conversation_to_order';
    case AutoReply = 'auto_reply';
    case Sales = 'sales';
    case DataQuestions = 'data_questions';
    case Measurements = 'measurements';

    public const CORE = 'ai_core';

    public const PLUS = 'ai_plus';

    public function capability(): string
    {
        return match ($this) {
            self::Help, self::FlowSetup, self::Digest, self::QuoteFollowups => self::CORE,
            default => self::PLUS,
        };
    }

    public function enabledByDefault(): bool
    {
        return $this === self::Help;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }
}
