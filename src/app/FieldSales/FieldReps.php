<?php

namespace App\FieldSales;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who is a field sales rep (ADR-0041): an active member holding the `field_sales` role template. The template's
 * grants stay editable on the roles screens; holding it is what puts a member on the dispatch list and makes the
 * orders they open `field_sales`. A rep covers the branches of their membership.
 */
final class FieldReps
{
    public const ROLE = 'field_sales';

    public static function isRep(User $user): bool
    {
        return self::branchesOf($user) !== null;
    }

    public static function covers(User $user, int $branchId): bool
    {
        return in_array($branchId, self::branchesOf($user) ?? [], true);
    }

    /**
     * Reps who cover a branch, by name.
     *
     * @return list<User>
     */
    public static function forBranch(int $branchId): array
    {
        return array_values(self::memberships()->filter(fn (Membership $m) => $m->branches->contains('id', $branchId))
            ->map(fn (Membership $m) => $m->user)->filter(fn (User $u) => ! $u->is_support)->sortBy('name')->values()->all());
    }

    /**
     * Every active rep, by name.
     *
     * @return list<User>
     */
    public static function all(): array
    {
        return array_values(self::memberships()->map(fn (Membership $m) => $m->user)
            ->filter(fn (User $u) => ! $u->is_support)->sortBy('name')->values()->all());
    }

    /**
     * The rep's branches, or null when the user is not a rep.
     *
     * @return list<int>|null
     */
    private static function branchesOf(User $user): ?array
    {
        $membership = Membership::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active)
            ->whereHas('roles.template', fn ($q) => $q->where('key', self::ROLE))->first();

        return $membership === null || $user->is_support ? null : $membership->branchIds();
    }

    /**
     * @return Collection<int, Membership>
     */
    private static function memberships(): Collection
    {
        return Membership::query()->with(['user', 'branches'])->where('status', MembershipStatus::Active)
            ->whereHas('roles.template', fn ($q) => $q->where('key', self::ROLE))->orderBy('id')->get()->toBase();
    }
}
