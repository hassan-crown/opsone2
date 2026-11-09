<?php

namespace App\Inbox;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Inbox\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who uses the WhatsApp inbox (AI-3): staff holding P-108, over the conversations their grant covers — by branch (the
 * customer's, or the default inbox branch for unknown contacts) or as the assignee. The list is narrowed by the same
 * grants as a direct open (ScopedQuery), so it never shows what opening would refuse. A conversation without a branch
 * (unknown contact, no default branch) is visible to facility-wide grants and to its assignee only.
 */
final class InboxAccess
{
    public static function any(User $user): bool
    {
        return app(InboxSettings::class)->enabled() && Access::canRead($user, Permission::P108);
    }

    public static function canOpen(User $user, Conversation $conversation): bool
    {
        return Access::canRead($user, Permission::P108, $conversation);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public static function scoped(Builder $query, User $user): Builder
    {
        return ScopedQuery::apply($query, $user, [Permission::P108], 'branch_id', null, 'assignee_id', reading: true);
    }
}
