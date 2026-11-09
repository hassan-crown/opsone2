<?php

namespace App\Ai\Inbox;

use App\Models\Ai\AiSuggestion;

/**
 * What ReplySuggester gives back. Only `ready` carries a suggestion; every other status is a reason to reply by hand.
 */
final readonly class SuggestOutcome
{
    public const READY = 'ready';

    /** Feature switched off, not licensed. */
    public const OFF = 'off';

    /** The staff member may not reply in this conversation. */
    public const DENIED = 'denied';

    /** Outside the 24-hour window (only an approved template may be sent), or the customer opted out. */
    public const CANNOT_REPLY = 'cannot_reply';

    /** No customer message to answer. */
    public const NOTHING = 'nothing';

    /** The model asked for a human, or its draft failed the checks (an unsourced number, a bad shape). */
    public const NO_SUGGESTION = 'no_suggestion';

    /** Credits used up. */
    public const NO_CREDIT = 'no_credit';

    /** Provider down, timeout, refused, `log` driver… */
    public const UNAVAILABLE = 'unavailable';

    public function __construct(public string $status, public ?AiSuggestion $suggestion = null) {}

    public function ready(): bool
    {
        return $this->status === self::READY && $this->suggestion !== null;
    }
}
