<?php

namespace App\Tickets;

use App\Enums\TicketStatus;
use App\Files\AttachmentService;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\User;
use App\Portal\PortalFiles;

/**
 * Ticket payloads. Two builders, never mixed: the STAFF one (inbox/page for P-59 holders, with internal notes) and the
 * CUSTOMER one (allow-list: public messages only, no staff names, no assignee, no internal data of any kind).
 */
final class TicketPresenter
{
    public function __construct(private readonly AttachmentService $files) {}

    /**
     * @return array<string, mixed>
     */
    public static function staffRow(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'type' => $ticket->type->value,
            'status' => $ticket->status->value,
            'customer' => $ticket->customer->name,
            'order' => $ticket->order === null ? null : ['id' => $ticket->order->id, 'number' => $ticket->order->number],
            'assignee' => $ticket->assignee === null ? null : ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name],
            'created_at' => $ticket->created_at->toIso8601String(),
            'waiting_since' => $ticket->last_customer_activity_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function staffDetail(Ticket $ticket, User $viewer): array
    {
        $ticket->loadMissing(['customer', 'order', 'assignee']);
        $messages = $ticket->messages()->with(['author', 'attachments'])->get();
        $status = $ticket->status;

        return self::staffRow($ticket) + [
            'resolution' => $ticket->resolution,
            'messages' => array_values($messages->map(fn (TicketMessage $m) => [
                'id' => $m->id,
                'visibility' => $m->visibility,
                'author_kind' => $m->author_kind,
                'author' => $m->author_kind === TicketMessage::STAFF ? $m->author?->name : $ticket->customer->name,
                'body' => $m->body,
                'created_at' => $m->created_at->toIso8601String(),
                'files' => array_values($m->attachments->map(fn (Attachment $a) => [
                    'name' => $a->original_name, 'size_bytes' => $a->size_bytes, 'url' => $this->files->downloadUrl($a, $viewer),
                ])->all()),
            ])->all()),
            'assignable' => array_map(fn (User $u) => ['id' => $u->id, 'name' => $u->name], TicketAccess::assignable($ticket)),
            'can' => [
                'take' => $status === TicketStatus::New,
                'public_reply' => in_array($status, [TicketStatus::InProgress, TicketStatus::AwaitingCustomer], true),
                'request_info' => $status === TicketStatus::InProgress,
                'resolve' => $status === TicketStatus::InProgress,
                'close' => $status === TicketStatus::Resolved,
                'reopen' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true),
                'assign' => $status !== TicketStatus::Closed,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerRow(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'type' => $ticket->type->value,
            'status' => $ticket->status->value,
            'order' => $ticket->order === null ? null : ['id' => $ticket->order->id, 'number' => $ticket->order->number],
            'created_at' => $ticket->created_at->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerDetail(Customer $customer, Ticket $ticket): array
    {
        $ticket->loadMissing('order');
        $messages = $ticket->messages()->with('attachments')->where('visibility', TicketMessage::PUBLIC)->get();

        return self::customerRow($ticket) + [
            'messages' => array_values($messages->map(fn (TicketMessage $m) => [
                'id' => $m->id,
                'mine' => $m->author_kind === TicketMessage::CUSTOMER,
                'body' => $m->body,
                'created_at' => $m->created_at->toIso8601String(),
                'files' => array_values($m->attachments->map(fn (Attachment $a) => [
                    'name' => $a->original_name, 'size_bytes' => $a->size_bytes, 'url' => PortalFiles::url($a, $customer),
                ])->all()),
            ])->all()),
            'can_close' => $ticket->status === TicketStatus::Resolved,
            'reopens_on_reply' => in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true),
        ];
    }
}
