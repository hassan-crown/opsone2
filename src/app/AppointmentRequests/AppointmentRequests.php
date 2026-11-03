<?php

namespace App\AppointmentRequests;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\AppointmentRequest;
use App\Models\Membership;
use App\Models\User;
use App\Workflow\AppointmentBooking;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees and works the appointment request queue (ADR-0039): P-54 (the appointment permission) in scope over the
 * request's branch, or as its assignee. Lists use the same scope as a direct open (ScopedQuery), so a count never
 * shows what opening would refuse; a request outside the scope is 404.
 */
final class AppointmentRequests
{
    public static function timezone(): string
    {
        return AppointmentBooking::timezone();
    }

    public static function any(User $user): bool
    {
        return Access::can($user, Permission::P54);
    }

    /**
     * @return Builder<AppointmentRequest>
     */
    public static function visible(User $user): Builder
    {
        return ScopedQuery::apply(AppointmentRequest::query(), $user, [Permission::P54], 'branch_id', null, 'assignee_id');
    }

    public static function canManage(User $user, AppointmentRequest $request): bool
    {
        return Access::can($user, Permission::P54, $request);
    }

    public static function find(User $user, int|string $id): AppointmentRequest
    {
        $request = AppointmentRequest::query()->find(is_numeric($id) ? (int) $id : 0);
        abort_if($request === null || ! self::canManage($user, $request), 404);

        return $request;
    }

    /**
     * Active members who could work this request: P-54 over its branch by their own scope (branch, region or
     * facility) — an `assigned`-only grant is not enough to be handed a request of a branch they do not cover.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function assignable(AppointmentRequest $request): array
    {
        $out = [];
        foreach (Membership::query()->with('user')->where('status', 'active')->orderBy('id')->get() as $membership) {
            $user = $membership->user;
            if (self::canWork($user, $request)) {
                $out[] = ['id' => (int) $user->id, 'name' => (string) $user->name];
            }
        }

        return $out;
    }

    public static function canWork(User $user, AppointmentRequest $request): bool
    {
        $probe = $request->replicate();
        $probe->assignee_id = null;

        return Access::can($user, Permission::P54, $probe);
    }
}
