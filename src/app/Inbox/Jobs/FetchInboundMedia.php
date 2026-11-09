<?php

namespace App\Inbox\Jobs;

use App\Files\AttachmentService;
use App\Models\Inbox\ConversationMessage;
use App\Notifications\Channels\WhatsApp\WhatsAppException;
use App\Whatsapp\Inbox\ConversationTransport;
use App\Whatsapp\Inbox\MediaRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Downloads one received file from Meta and keeps it as a private attachment of the conversation (ADR-0006): the type
 * and size are checked before the download and again on the bytes; a refused file is recorded as `rejected` and never
 * stored. Media URLs at Meta expire, so retries are short (30 s → 5 min, 4 attempts); then the file is `failed`.
 */
class FetchInboundMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(ConversationTransport $transport, AttachmentService $attachments): void
    {
        $message = ConversationMessage::query()->with('conversation')->find($this->messageId);
        if ($message === null || $message->media_status !== ConversationMessage::MEDIA_PENDING || $message->media_provider_id === null) {
            return;
        }

        $types = (array) config('whatsapp.inbox.media_types');
        $max = max(1, (int) config('whatsapp.inbox.max_media_kb')) * 1024;

        try {
            $file = $transport->fetchMedia($message->media_provider_id, $types, $max);
        } catch (MediaRejected $e) {
            $this->finish($message, ConversationMessage::MEDIA_REJECTED, $e->reason);

            return;
        } catch (WhatsAppException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

                return;
            }
            $this->finish($message, ConversationMessage::MEDIA_FAILED, $e->retryable ? 'retries_exhausted' : (string) ($e->providerCode ?? 'refused'));

            return;
        }

        try {
            DB::transaction(function () use ($message, $file, $types, $attachments) {
                $locked = ConversationMessage::query()->lockForUpdate()->find($message->id);
                if ($locked === null || $locked->media_status !== ConversationMessage::MEDIA_PENDING) {
                    return;
                }
                $extension = $types[$file->mime] ?? 'bin';
                $attachment = $attachments->storeGenerated(
                    $message->conversation, $file->bytes, 'whatsapp-'.$message->id, $extension, $file->mime, 'whatsapp_media',
                );
                $locked->forceFill(['attachment_id' => $attachment->id, 'media_status' => ConversationMessage::MEDIA_STORED, 'media_mime' => $file->mime, 'error' => null])->save();
            });
        } catch (Throwable $e) {
            // Storage quota reached or the disk refused: the message stays, without its file.
            report($e);
            $this->finish($message, ConversationMessage::MEDIA_FAILED, 'storage');
        }
    }

    private function finish(ConversationMessage $message, string $status, string $code): void
    {
        $message->forceFill(['media_status' => $status, 'error' => mb_substr($code, 0, 64)])->save();
    }
}
