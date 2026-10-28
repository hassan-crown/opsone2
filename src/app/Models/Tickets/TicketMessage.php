<?php

namespace App\Models\Tickets;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One message of a ticket. `visibility` is `public` (the customer sees it and its files) or `internal` (staff note:
 * never in a customer payload, notification or download). Access to its files follows the ticket (P-59 for staff).
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $visibility
 * @property string $author_kind
 * @property int|null $user_id
 * @property string $body
 * @property CarbonInterface $created_at
 * @property-read Ticket $ticket
 * @property-read User|null $author
 */
class TicketMessage extends Model implements AttachmentOwner
{
    public const PUBLIC = 'public';

    public const INTERNAL = 'internal';

    public const CUSTOMER = 'customer';

    public const STAFF = 'staff';

    public const UPDATED_AT = null;

    protected $table = 'ticket_messages';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::PUBLIC;
    }

    public function accessBranchIds(): array
    {
        return $this->ticket->accessBranchIds();
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return $this->ticket->accessAssigneeIds();
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P59;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P59;
    }
}
