<?php

namespace App\Ai;

use InvalidArgumentException;

/**
 * A named AI task (plan §3.1). The model, effort, output size, timeout class, credit weight and batch flag come from
 * config/ai.php so they can change without a code change. Features ask the gateway for a task, never for a model.
 */
enum AiTask: string
{
    case IntentClassify = 'intent.classify';
    case HelpAnswer = 'help.answer';
    case AutoreplyIntent = 'autoreply.intent';
    case ReplySuggest = 'reply.suggest';
    case OrderDraft = 'order.draft';
    case LeadQualify = 'lead.qualify';
    case DataPlan = 'data.plan';
    case DataAsk = 'data.ask';
    case QuoteFollowup = 'quote.followup';
    case DigestDaily = 'digest.daily';
    case ContentDraft = 'content.draft';
    case FlowPropose = 'flow.propose';
    case LostAnalysis = 'lost.analysis';
    case MeasureRead = 'measure.read';

    public function feature(): AiFeature
    {
        return match ($this) {
            self::HelpAnswer => AiFeature::Help,
            self::FlowPropose => AiFeature::FlowSetup,
            self::DigestDaily => AiFeature::Digest,
            self::QuoteFollowup => AiFeature::QuoteFollowups,
            self::IntentClassify, self::ReplySuggest => AiFeature::Inbox,
            self::OrderDraft => AiFeature::ConversationToOrder,
            self::AutoreplyIntent => AiFeature::AutoReply,
            self::LeadQualify, self::ContentDraft, self::LostAnalysis => AiFeature::Sales,
            self::DataAsk, self::DataPlan => AiFeature::DataQuestions,
            self::MeasureRead => AiFeature::Measurements,
        };
    }

    public function model(): string
    {
        $tier = (string) $this->setting('tier');
        $model = config("ai.models.{$tier}");

        return is_string($model) && $model !== '' ? $model : throw new InvalidArgumentException("No model for AI tier {$tier}.");
    }

    public function effort(): string
    {
        return (string) $this->setting('effort');
    }

    public function maxTokens(): int
    {
        return (int) $this->setting('max_tokens');
    }

    public function interactive(): bool
    {
        return (bool) $this->setting('interactive');
    }

    public function timeoutSeconds(): int
    {
        return (int) config('ai.timeouts.'.($this->interactive() ? 'interactive' : 'background'));
    }

    /** Credits charged for one successful call (plan §4.3). */
    public function weight(): int
    {
        return max(0, (int) $this->setting('weight'));
    }

    public function batch(): bool
    {
        return (bool) $this->setting('batch');
    }

    private function setting(string $key): mixed
    {
        // Task names contain dots, so no dot-notation lookup.
        $tasks = config('ai.tasks');
        $value = is_array($tasks) && is_array($tasks[$this->value] ?? null) ? ($tasks[$this->value][$key] ?? null) : null;

        return $value ?? throw new InvalidArgumentException("config/ai.php has no {$key} for task {$this->value}.");
    }
}
