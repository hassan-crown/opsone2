<?php

namespace App\Notifications;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    /** A send failed; it is retried with backoff. */
    case Failed = 'failed';
    case Sent = 'sent';
    /** Retries exhausted; needs a person to look at it. */
    case Dead = 'dead';
    /** The channel is not configured, so nothing was attempted (never silently "sent"). */
    case Skipped = 'skipped';
}
