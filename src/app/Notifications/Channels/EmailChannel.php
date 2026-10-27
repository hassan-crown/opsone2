<?php

namespace App\Notifications\Channels;

use App\Integrations\FacilityMail;
use App\Mail\OrgMessage;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an outbox mail through the facility's own SMTP account, or the application mailer (`log` in development).
 */
final class EmailChannel
{
    public function __construct(private readonly FacilityMail $mail) {}

    public function send(OutboxMessage $message): void
    {
        $payload = $message->payload ?? [];

        $mailable = (new OrgMessage(
            title: (string) ($payload['title'] ?? ''),
            lines: array_values((array) ($payload['lines'] ?? [])),
            action: $payload['action'] ?? null,
            dir: (string) ($payload['dir'] ?? 'rtl'),
        ))->locale((string) ($payload['locale'] ?? 'ar'));

        $from = $this->mail->from();
        if ($from !== null) {
            $mailable->from($from['address'], $from['name']);
        }

        Mail::mailer($this->mail->mailer())->to($message->recipient)->send($mailable);
    }
}
