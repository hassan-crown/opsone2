<?php

namespace App\Tickets;

use App\Files\AttachmentService;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Appends a message to a ticket with its files, inside the caller's transaction. A staff upload is authorized on the
 * message through the central Gate (P-59 over the ticket); a customer's is the customer service's own check.
 */
final class TicketMessages
{
    public const MAX_FILES = 5;

    public function __construct(private readonly AttachmentService $files) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function add(Ticket $ticket, string $visibility, ?User $staff, string $body, array $files = []): TicketMessage
    {
        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'visibility' => $visibility,
            'author_kind' => $staff === null ? TicketMessage::CUSTOMER : TicketMessage::STAFF,
            'user_id' => $staff?->id,
            'body' => $body,
        ]);
        $message->setRelation('ticket', $ticket);

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if ($staff === null) {
                $this->files->storeFromCustomer($message, $file, 'ticket');
            } else {
                $this->files->store($staff, $message, $file, 'ticket');
            }
        }

        return $message;
    }
}
