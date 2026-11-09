<?php

namespace App\Models\Inbox;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use App\Inbox\ServiceWindow;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One WhatsApp contact of the facility's number (AI-3, ADR-0030 amendment). Scoped by its branch (the linked customer's
 * branch, else the facility's default inbox branch) and by its assignee; P-108 is checked against these facts. It owns
 * the private files received in the conversation (ADR-0006).
 *
 * @property int $id
 * @property string $wa_id
 * @property string|null $contact_name
 * @property int|null $customer_id
 * @property int|null $branch_id
 * @property int|null $assignee_id
 * @property CarbonInterface|null $last_inbound_at
 * @property CarbonInterface|null $last_message_at
 * @property int $unread_count
 * @property bool $is_group AI-6: a message came from a WhatsApp group; never answered automatically
 * @property CarbonInterface|null $ai_flagged_at AI-4 suspicion flag: staff see a warning, no automatic reply (AI-6)
 * @property CarbonInterface $created_at
 * @property-read Customer|null $customer
 * @property-read User|null $assignee
 * @property-read Branch|null $branch
 */
class Conversation extends Model implements AttachmentOwner
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'last_message_at' => 'datetime',
            'ai_flagged_at' => 'datetime',
            'is_group' => 'boolean',
            'unread_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function windowOpen(?CarbonInterface $at = null): bool
    {
        return ServiceWindow::isOpen($this->last_inbound_at, $at);
    }

    public function accessBranchIds(): array
    {
        return $this->branch_id === null ? [] : [(int) $this->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return $this->assignee_id === null ? [] : [(int) $this->assignee_id];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P108;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P108;
    }
}
