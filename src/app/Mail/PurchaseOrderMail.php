<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A purchase order to its supplier (ADR-0038): the facility's subject and body, with the order's PDF attached.
 */
class PurchaseOrderMail extends Mailable
{
    public function __construct(
        public string $mailSubject,
        public string $body,
        private readonly string $pdf,
        public string $filename,
        public string $dir = 'rtl',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.org-message', with: [
            'lines' => array_values(array_filter(array_map('trim', explode("\n", $this->body)), fn (string $l) => $l !== '')),
            'action' => null,
            'dir' => $this->dir,
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
