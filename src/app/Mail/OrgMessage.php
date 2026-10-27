<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain transactional message of the organization flows (invitation links, ownership-proof codes).
 */
class OrgMessage extends Mailable
{
    /**
     * @param  list<string>  $lines
     * @param  array{label: string, url: string}|null  $action
     */
    public function __construct(
        public string $title,
        public array $lines,
        public ?array $action = null,
        public string $dir = 'rtl',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.org-message');
    }
}
