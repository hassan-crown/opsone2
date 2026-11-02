<?php

namespace App\Notifications\Channels;

use App\Integrations\FacilityMail;
use App\Mail\OrgMessage;
use App\Mail\PurchaseOrderMail;
use App\Models\OutboxMessage;
use App\Models\Procurement\PurchaseOrderSend;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Sends an outbox mail through the facility's own SMTP account, or the application mailer (`log` in development).
 */
final class EmailChannel
{
    public function __construct(private readonly FacilityMail $mail) {}

    public function send(OutboxMessage $message): void
    {
        $payload = $message->payload ?? [];

        if ($message->kind === 'purchase_order') {
            $this->sendPurchaseOrder($message, (int) ($payload['purchase_order_send_id'] ?? 0));

            return;
        }

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

    /**
     * A purchase order to its supplier with the stored PDF attached (ADR-0038).
     */
    private function sendPurchaseOrder(OutboxMessage $message, int $sendId): void
    {
        $send = PurchaseOrderSend::query()->with(['document.attachment', 'purchaseOrder'])->findOrFail($sendId);
        $file = $send->document->attachment;

        $mailable = (new PurchaseOrderMail(
            mailSubject: $send->subject,
            body: $send->body,
            pdf: (string) Storage::disk($file->disk)->get($file->path),
            filename: $send->purchaseOrder->number.'.pdf',
            dir: $send->locale === 'ar' ? 'rtl' : 'ltr',
        ))->locale($send->locale);

        $from = $this->mail->from();
        if ($from !== null) {
            $mailable->from($from['address'], $from['name']);
        }

        Mail::mailer($this->mail->mailer())->to($message->recipient)->send($mailable);
    }
}
