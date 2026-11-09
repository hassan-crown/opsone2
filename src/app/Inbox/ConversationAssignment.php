<?php

namespace App\Inbox;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\MembershipStatus;
use App\Models\Inbox\Conversation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who follows a conversation (AI-3). Assigning needs P-108 over the conversation; the new assignee must be an active
 * staff member who would hold P-108 over it once assigned (so an assignment never hands a conversation to someone who cannot open it).
 * `null` returns it to the unassigned queue. Audited.
 */
final class ConversationAssignment
{
    public function assign(User $actor, Conversation $conversation, ?int $assigneeId): Conversation
    {
        Access::authorize($actor, Permission::P108, $conversation);
        if ($assigneeId !== null && ! self::eligible($assigneeId, $conversation)) {
            throw ValidationException::withMessages(['assignee_id' => __('inbox.errors.assignee_not_eligible')]);
        }

        return DB::transaction(function () use ($actor, $conversation, $assigneeId) {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $before = $locked->assignee_id;
            if ($before === $assigneeId) {
                return $locked;
            }
            $locked->forceFill(['assignee_id' => $assigneeId])->save();
            Audit::record(AuditAction::ConversationAssigned, $locked, $actor, before: ['assignee_id' => $before], after: ['assignee_id' => $assigneeId], label: $locked->contact_name ?? $locked->wa_id);

            return $locked;
        });
    }

    public static function eligible(int $userId, Conversation $conversation): bool
    {
        $user = User::query()->find($userId);
        $active = Membership::query()->where('user_id', $userId)->where('status', MembershipStatus::Active)->exists();

        if ($user === null || ! $active || $user->is_support) {
            return false;
        }
        // Judged as it will be after the assignment, so a member whose grant is `assigned` can receive it.
        $probe = $conversation->replicate();
        $probe->assignee_id = $userId;

        return Access::can($user, Permission::P108, $probe);
    }

    /**
     * Candidates for the picker: active members who could open the conversation.
     *
     * @return list<User>
     */
    public static function candidates(Conversation $conversation): array
    {
        $ids = Membership::query()->where('status', MembershipStatus::Active)->pluck('user_id');

        return array_values(User::query()->whereIn('id', $ids)->where('is_support', false)->orderBy('name')->get()
            ->filter(fn (User $u) => self::eligible($u->id, $conversation))->all());
    }
}
