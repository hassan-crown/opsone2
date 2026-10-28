<?php

namespace App\Workflow;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\MembershipStatus;
use App\Enums\TaskStatus;
use App\Models\Membership;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Work lists (SHR-02, `TaskList`): my work, my department inbox, unassigned work I follow, and eligible members with
 * their open work count for assignment. Every row is re-checked with the Gate (P-24 to see, P-22 to assign).
 */
final class TaskQueries
{
    public const LIMIT = 200;

    /**
     * @return Collection<int, Task>
     */
    public static function mine(User $user): Collection
    {
        return self::visible($user, Task::query()->where('assignee_id', $user->id));
    }

    /**
     * Unassigned work in the user's department inbox that they could take (M-07-T10).
     *
     * @return Collection<int, Task>
     */
    public static function inbox(User $user): Collection
    {
        $department = Membership::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active)->value('department_id');
        if ($department === null) {
            return collect();
        }

        return self::visible($user, Task::query()->where('department_id', $department)->where('status', TaskStatus::Unassigned->value));
    }

    /**
     * Unassigned work the user follows (order responsible person or owner fallback) — never left without a follower.
     *
     * @return Collection<int, Task>
     */
    public static function followed(User $user): Collection
    {
        return self::visible($user, Task::query()->where('follow_up_user_id', $user->id)->where('status', TaskStatus::Unassigned->value));
    }

    /**
     * Eligible members for a task with their open work count (for `TaskAssign`); empty unless the actor may assign it.
     *
     * @return list<array{user_id: int, name: string, open_tasks: int}>
     */
    public static function eligibleFor(User $actor, Task $task): array
    {
        if (! Access::can($actor, Permission::P22, $task) && ! Access::can($actor, Permission::P23, $task)) {
            return [];
        }

        $members = Membership::query()->with('user')->where('status', MembershipStatus::Active)
            ->when($task->department_id !== null, fn ($q) => $q->where('department_id', $task->department_id))
            ->get()
            ->filter(fn (Membership $m) => TaskEligibility::allows($m->user_id, $task));
        $counts = Task::query()->whereIn('assignee_id', $members->pluck('user_id'))->whereIn('status', TaskStatus::open())
            ->selectRaw('assignee_id, count(*) as open_tasks')->groupBy('assignee_id')->pluck('open_tasks', 'assignee_id');

        return array_values($members->map(fn (Membership $m) => [
            'user_id' => $m->user_id,
            'name' => $m->user->name,
            'open_tasks' => (int) ($counts[$m->user_id] ?? 0),
        ])->sortBy('open_tasks')->all());
    }

    /**
     * @param  Builder<Task>  $query
     * @return Collection<int, Task>
     */
    private static function visible(User $user, $query): Collection
    {
        return $query->with(['order', 'action'])->whereIn('status', TaskStatus::open())->orderBy('due_at')->orderBy('id')
            ->limit(self::LIMIT)->get()
            ->filter(fn (Task $t) => Access::can($user, Permission::P24, $t))
            ->values();
    }
}
