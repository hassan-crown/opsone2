<?php

namespace App\Workflow;

use App\Enums\MembershipStatus;
use App\Enums\TaskStatus;
use App\Models\MembershipRole;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\Task;

/**
 * Who is responsible for a stage or receives an action's work (ADR-0038, W13): the order's responsible person, a
 * member, a department inbox, any member holding a role, or a person per branch — and, for an action, «the stage's
 * responsible». A role or branch never assigns work to people who could not open it: candidates pass
 * TaskEligibility (P-24 over the order's branch). When nobody qualifies, the order's responsible person is the
 * fallback, so work is never addressed to nobody by configuration alone.
 */
final class StageAssignment
{
    public const ORDER_ASSIGNEE = 'order_assignee';

    public const MEMBER = 'member';

    public const DEPARTMENT = 'department';

    public const ROLE = 'role';

    public const PER_BRANCH = 'per_branch';

    /** Action only: the work goes to the stage's responsible person. */
    public const STAGE = 'stage';

    /**
     * The person a target names for this order, or null (a department inbox, or nobody eligible).
     *
     * @param  array<string, mixed>  $target
     */
    public static function resolve(array $target, Order $order, ?OrderStage $stage = null, ?Task $task = null): ?int
    {
        $task ??= self::probe($order, $stage);

        $candidate = match ($target['type'] ?? null) {
            self::ORDER_ASSIGNEE => $order->assignee_id,
            self::MEMBER => isset($target['id']) ? (int) $target['id'] : null,
            self::ROLE => self::fromRole((int) ($target['id'] ?? 0), $task),
            self::PER_BRANCH => self::fromBranch($target, $order),
            self::STAGE => $stage?->follow_up_user_id,
            default => null,
        };

        if ($candidate !== null && TaskEligibility::allows((int) $candidate, $task)) {
            return (int) $candidate;
        }
        if (in_array($target['type'] ?? null, [self::ROLE, self::PER_BRANCH, self::STAGE], true)
            && $order->assignee_id !== null && TaskEligibility::allows((int) $order->assignee_id, $task)) {
            return (int) $order->assignee_id;
        }

        return null;
    }

    /**
     * Eligible members holding the role; the one with the fewest open tasks (then the oldest member) takes it.
     */
    private static function fromRole(int $roleId, Task $task): ?int
    {
        $users = MembershipRole::query()->where('role_template_id', $roleId)
            ->join('memberships', 'memberships.id', '=', 'membership_roles.membership_id')
            ->where('memberships.status', MembershipStatus::Active->value)
            ->orderBy('memberships.user_id')
            ->pluck('memberships.user_id')->map(fn ($id) => (int) $id)->unique()->values();

        $eligible = $users->filter(fn (int $id) => TaskEligibility::allows($id, $task))->values();
        if ($eligible->isEmpty()) {
            return null;
        }

        $load = Task::query()->whereIn('assignee_id', $eligible->all())
            ->whereIn('status', [TaskStatus::Assigned->value, TaskStatus::InProgress->value])
            ->selectRaw('assignee_id, count(*) as total')->groupBy('assignee_id')->pluck('total', 'assignee_id');

        return $eligible->sortBy(fn (int $id) => [(int) ($load[$id] ?? 0), $id])->first();
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private static function fromBranch(array $target, Order $order): ?int
    {
        foreach ((array) ($target['branches'] ?? []) as $row) {
            if (is_array($row) && (int) ($row['branch_id'] ?? 0) === (int) $order->branch_id) {
                return isset($row['user_id']) ? (int) $row['user_id'] : null;
            }
        }

        return $order->assignee_id;
    }

    /**
     * An unsaved task of the order, to ask TaskEligibility whether someone could open the work.
     */
    private static function probe(Order $order, ?OrderStage $stage): Task
    {
        $task = new Task(['order_id' => $order->id, 'order_stage_id' => $stage?->id]);
        $task->setRelation('order', $order);

        return $task;
    }

    /**
     * The target shape stored on the order: `{type, id?}` plus `branches` for a per-branch target.
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public static function stored(array $target): array
    {
        return array_intersect_key($target, ['type' => true, 'id' => true, 'branches' => true]);
    }
}
