<?php

namespace App\Ai\Sales;

use App\Models\Ai\AiSuggestion;

/**
 * What LeadQualifier gives back. Only `ready` carries a suggestion; every other status is a reason to work by hand.
 */
final readonly class LeadOutcome
{
    public const READY = 'ready';

    public const OFF = 'off';

    public const DENIED = 'denied';

    /** The conversation is not a new lead (a known customer with another intent). */
    public const NOT_LEAD = 'not_lead';

    /** A message in the conversation looks like an attempt to steer the assistant: staff handle it by hand. */
    public const FLAGGED = 'flagged';

    public const NOTHING = 'nothing';

    public const NO_SUGGESTION = 'no_suggestion';

    public const NO_CREDIT = 'no_credit';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(public string $status, public ?AiSuggestion $suggestion = null) {}

    public function ready(): bool
    {
        return $this->status === self::READY && $this->suggestion !== null;
    }
}
