<?php

namespace App\Agreements;

use App\Access\GrantResolver;
use App\Access\Permission;
use App\Access\ScopeResolver;
use App\Models\DiscountLimit;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;

/**
 * Discount limits (AGR-02, SAL-09) in basis points of the discount base. A member's own row wins; otherwise the
 * highest limit among their role templates — for an order, only the roles whose scope covers that order, so a wider
 * limit held for another branch never applies here; otherwise 0. The owner has no limit (null) and alone sets the
 * others.
 */
final class DiscountLimits
{
    public const KINDS = ['custom_bp', 'ready_bp', 'approval_bp'];

    /**
     * @param  'custom_bp'|'ready_bp'|'approval_bp'  $kind
     * @return int|null null = no limit (the owner)
     */
    public static function of(User $user, string $kind, ?Order $order = null): ?int
    {
        $membership = Membership::query()->where('user_id', $user->id)->first();
        if ($membership === null) {
            return 0;
        }
        if (Facility::current()?->owner_membership_id === $membership->id) {
            return null;
        }

        $own = DiscountLimit::query()->where('membership_id', $membership->id)->first();
        if ($own !== null) {
            return (int) $own->{$kind};
        }

        $roles = $membership->roles()->with('template.grants')->get();
        if ($order !== null) {
            $resolver = app(GrantResolver::class);
            $scopes = app(ScopeResolver::class);
            // The permissions a limit is used under: pricing and sending a quote or a change, or deciding a request.
            $relevant = $kind === 'approval_bp' ? [Permission::P31] : [Permission::P29, Permission::P30, Permission::P36];
            $roles = $roles->filter(function (MembershipRole $role) use ($membership, $order, $resolver, $scopes, $user, $relevant) {
                $grants = $resolver->resolve($membership->department_id, $membership->branchIds(), [[
                    'grants' => array_values($role->template->grants->map(fn ($g) => [$g->permission, $g->scope])->all()),
                    'scope' => $role->scope, 'targets' => $role->targets,
                ]]);
                foreach ($grants as $grant) {
                    if (in_array($grant->permission, $relevant, true) && $scopes->covers($grant, $user, $order)) {
                        return true;
                    }
                }

                return false;
            });
        }
        $roleTemplateIds = $roles->pluck('role_template_id');

        return (int) (DiscountLimit::query()->whereIn('role_template_id', $roleTemplateIds)->max($kind) ?? 0);
    }

    /**
     * Whether `discount` is above `limitBp` of `base` (exact integer comparison, no rounding).
     */
    public static function exceeds(Money $discount, Money $base, ?int $limitBp): bool
    {
        if ($limitBp === null || $discount->isZero()) {
            return false;
        }
        if ($base->isZero()) {
            return true;
        }

        return bccomp(bcmul((string) $discount->minor, (string) Money::BP), bcmul((string) $limitBp, (string) $base->minor)) > 0;
    }

    /**
     * The discount as basis points of its base, rounded half-up (display and audit only — decisions compare exactly).
     */
    public static function share(Money $discount, Money $base): int
    {
        return $base->isZero() ? 0 : Money::of($discount->minor)->ratio(Money::BP, $base->minor)->minor;
    }
}
