<?php

namespace App\Workflow;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\MembershipStatus;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;

/**
 * Who may hold a task (WF-04, WF-07): an active staff member (never a support identity), inside the task's department
 * when it sits in an inbox, who could open the task once it is theirs (P-24 over the order's branch). A role marks
 * eligible people; it never assigns work to all of them.
 */
final class TaskEligibility
{
    public static function allows(?int $userId, Task $task): bool
    {
        if ($userId === null) {
            return false;
        }

        $user = User::query()->find($userId);
        $membership = Membership::query()->where('user_id', $userId)->where('status', MembershipStatus::Active)->first();

        if ($user === null || $user->is_support || $membership === null) {
            return false;
        }
        if ($task->department_id !== null && $membership->department_id !== $task->department_id) {
            return false;
        }

        return Access::can($user, Permission::P24, $task->assignedTo($userId));
    }

    /**
     * The person following an order's work: its responsible person while active, otherwise the facility owner, so
     * no work is ever left without a known follower (WF-07).
     */
    public static function followerOf(Order $order): ?int
    {
        if ($order->assignee_id !== null && Membership::query()->where('user_id', $order->assignee_id)->where('status', MembershipStatus::Active)->exists()) {
            return (int) $order->assignee_id;
        }

        $owner = Facility::current()?->owner;

        return $owner === null ? null : (int) $owner->user_id;
    }
}
