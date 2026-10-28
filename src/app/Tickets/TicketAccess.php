<?php

namespace App\Tickets;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Enums\MembershipStatus;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\Tickets\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who works on customer tickets (P-59, SHR-08): staff whose grant covers the ticket's branch or assignment. The
 * customer's own access (P-71) is PortalAccess; staff access to a customer file never reaches a customer session.
 */
final class TicketAccess
{
    public static function any(User $user): bool
    {
        return Access::can($user, Permission::P59);
    }

    public static function canWork(User $user, Ticket $ticket): bool
    {
        return Access::can($user, Permission::P59, $ticket);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public static function scoped(Builder $query, User $user): Builder
    {
        return ScopedQuery::apply($query, $user, [Permission::P59], 'branch_id', null, 'assignee_id');
    }

    /**
     * A valid responsible person: an active staff member who holds P-59 over the ticket.
     */
    public static function isEligible(?int $userId, Ticket $ticket): bool
    {
        if ($userId === null) {
            return false;
        }
        $user = User::query()->find($userId);
        $active = Membership::query()->where('user_id', $userId)->where('status', MembershipStatus::Active)->exists();

        return $user !== null && $active && ! $user->is_support && Access::can($user, Permission::P59, $ticket);
    }

    /**
     * Candidates for the assignee picker: active members who could work the ticket.
     *
     * @return list<User>
     */
    public static function assignable(Ticket $ticket): array
    {
        $ids = Membership::query()->where('status', MembershipStatus::Active)->pluck('user_id');

        return array_values(User::query()->whereIn('id', $ids)->where('is_support', false)->orderBy('name')->get()
            ->filter(fn (User $u) => Access::can($u, Permission::P59, $ticket))->all());
    }

    /**
     * The responsible person at creation or reopening: the linked order's owner when eligible, else the facility owner
     * (a follow-up goes to the owner when the assignee is not eligible; the «general tickets officer» setting is not part of this phase; the owner is its documented default).
     */
    public static function defaultAssignee(Ticket $ticket): ?int
    {
        $order = $ticket->order;
        if ($order !== null && self::isEligible($order->assignee_id, $ticket)) {
            return $order->assignee_id;
        }
        $owner = Facility::current()?->owner?->user_id;

        return $owner;
    }
}
