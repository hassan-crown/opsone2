<?php

namespace App\FieldSales;

use App\Access\Access;
use App\Access\Authorizer;
use App\Access\DelegationGuard;
use App\Access\Enums\Scope;
use App\Access\Grant;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\DiscountLimits;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\MemberGrantEffect;
use App\Models\DiscountLimit;
use App\Models\MemberGrant;
use App\Models\Membership;
use App\Models\RoleTemplateGrant;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The supervisor's control over a rep (ADR-0041): turn a rep's field permissions off or back on, and set the rep's
 * discount limit — inside two ceilings.
 *
 * - **Who:** a supervisor holding P-105 over every branch of the rep's membership (branch, region or facility scope).
 *   A supervisor outside the rep's branch or region is refused.
 * - **Owner's ceiling:** the rep's role templates (edited on the roles screens) say which permissions a rep may hold
 *   at most; the settings in force say the highest discount (`max_discount_bp`).
 * - **The supervisor's own ceiling:** turning a permission on is a delegation (`DelegationGuard`: they must hold it
 *   themselves); a discount limit may not exceed their own.
 *
 * «Off» is a deny override (`member_grants`, `granted_by` = the supervisor); «on» removes it, so the rep's template
 * grant applies again. The discount is the rep's own `discount_limits` row. Every change is audited before/after. The
 * rep stays signed in: the Gate reads the member's grants again on their next request.
 */
final class FieldRepAccess
{
    /** The rep permissions a supervisor controls: prepare and price quotes, send documents and signing links, convert
     * leads and open orders, see payment status and amounts, record measurements, prepare contracts. */
    public const TOGGLES = ['P-29', 'P-30', 'P-15', 'P-37', 'P-14', 'P-32'];

    public function __construct(
        private readonly DelegationGuard $delegation,
        private readonly Authorizer $authorizer,
    ) {}

    public static function canManage(User $supervisor, User $rep): bool
    {
        if ($supervisor->id === $rep->id || ! FieldReps::isRep($rep)) {
            return false;
        }
        $membership = Membership::query()->where('user_id', $rep->id)->first();

        return $membership !== null && Access::can($supervisor, Permission::P105, $membership);
    }

    /**
     * The reps the supervisor manages, by name.
     *
     * @return list<User>
     */
    public static function team(User $supervisor): array
    {
        if (! Access::can($supervisor, Permission::P105)) {
            return [];
        }

        return array_values(array_filter(FieldReps::all(), fn (User $rep) => self::canManage($supervisor, $rep)));
    }

    /**
     * Each controllable permission: within the owner's ceiling, on or off, and whether this supervisor could turn it on.
     *
     * @return list<array{permission: string, label: string, ceiling: bool, enabled: bool, can_enable: bool}>
     */
    public function state(User $supervisor, User $rep): array
    {
        $membership = self::membership($rep);
        $ceiling = self::ceiling($membership);
        $denied = MemberGrant::query()->where('membership_id', $membership->id)->where('effect', MemberGrantEffect::Deny->value)
            ->pluck('permission')->map(fn ($p) => $p instanceof Permission ? $p->value : (string) $p)->all();

        return array_map(fn (string $code) => [
            'permission' => $code,
            'label' => Permission::from($code)->label(),
            'ceiling' => in_array($code, $ceiling, true),
            'enabled' => in_array($code, $ceiling, true) && ! in_array($code, $denied, true),
            'can_enable' => in_array($code, $ceiling, true) && $this->holds($supervisor, Permission::from($code)),
        ], self::TOGGLES);
    }

    /**
     * @return array{limit_bp: int|null, ceiling_bp: int, own_bp: int|null}
     */
    public static function discount(User $supervisor, User $rep): array
    {
        return [
            'limit_bp' => DiscountLimits::of($rep, 'custom_bp'),
            'ceiling_bp' => FieldSalesConfig::current()['max_discount_bp'],
            'own_bp' => DiscountLimits::of($supervisor, 'custom_bp'),
        ];
    }

    public function set(User $supervisor, User $rep, string $code, bool $on, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        self::authorize($supervisor, $rep);
        if (! in_array($code, self::TOGGLES, true)) {
            throw ValidationException::withMessages(['permission' => __('field_sales.team.validation.permission')]);
        }
        $permission = Permission::from($code);

        LockingTransaction::run(function () use ($supervisor, $rep, $permission, $on, $source) {
            $membership = Membership::query()->lockForUpdate()->findOrFail(self::membership($rep)->id);
            if (! in_array($permission->value, self::ceiling($membership), true)) {
                throw ValidationException::withMessages(['permission' => __('field_sales.team.validation.ceiling')]);
            }
            if ($on) {
                // Turning a grant on is a delegation: never wider than what the supervisor holds.
                $this->delegation->assertCanGrant($supervisor, [new Grant($permission, Scope::Assigned)]);
            }
            $deny = MemberGrant::query()->where('membership_id', $membership->id)->where('permission', $permission->value)
                ->where('effect', MemberGrantEffect::Deny->value);
            $before = ! (clone $deny)->exists();
            if ($before === $on) {
                return;
            }
            if ($on) {
                $deny->delete();
            } else {
                MemberGrant::query()->create([
                    'membership_id' => $membership->id, 'permission' => $permission->value, 'effect' => MemberGrantEffect::Deny->value,
                    'scope' => null, 'targets' => null, 'granted_by_membership_id' => Membership::query()->where('user_id', $supervisor->id)->value('id'),
                ]);
            }
            $membership->touch();
            Audit::record(AuditAction::FieldRepPermissionChanged, $membership, $supervisor,
                before: ['permission' => $permission->value, 'enabled' => $before], after: ['permission' => $permission->value, 'enabled' => $on],
                source: $source, branchIds: $membership->branchIds(), label: $rep->name);
        });
        $this->authorizer->flush();
    }

    public function setDiscount(User $supervisor, User $rep, int $bp, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        self::authorize($supervisor, $rep);
        $ceiling = FieldSalesConfig::current()['max_discount_bp'];
        $own = DiscountLimits::of($supervisor, 'custom_bp');
        if ($bp < 0 || $bp > $ceiling) {
            throw ValidationException::withMessages(['limit_bp' => __('field_sales.team.validation.discount_ceiling', ['max' => $ceiling / 100])]);
        }
        if ($own !== null && $bp > $own) {
            throw ValidationException::withMessages(['limit_bp' => __('field_sales.team.validation.discount_own', ['max' => $own / 100])]);
        }

        LockingTransaction::run(function () use ($supervisor, $rep, $bp, $source) {
            $membership = Membership::query()->lockForUpdate()->findOrFail(self::membership($rep)->id);
            $before = DiscountLimits::of($rep, 'custom_bp');
            $row = DiscountLimit::query()->where('membership_id', $membership->id)->first()
                ?? new DiscountLimit(['membership_id' => $membership->id, 'approval_bp' => 0]);
            $row->forceFill(['custom_bp' => $bp, 'ready_bp' => $bp])->save();
            Audit::record(AuditAction::FieldRepDiscountChanged, $membership, $supervisor,
                before: ['custom_bp' => $before], after: ['custom_bp' => $bp], source: $source, branchIds: $membership->branchIds(), label: $rep->name);
        });
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(User $supervisor, User $rep): void
    {
        if (! self::canManage($supervisor, $rep)) {
            throw new AuthorizationException(__('access.denied.no_grant'));
        }
    }

    /**
     * Permission codes the rep's role templates hold: the owner's ceiling.
     *
     * @return list<string>
     */
    private static function ceiling(Membership $membership): array
    {
        $templates = $membership->roles()->pluck('role_template_id');

        return array_values(array_unique(array_map(
            fn ($p) => $p instanceof Permission ? $p->value : (string) $p,
            RoleTemplateGrant::query()->whereIn('role_template_id', $templates)->pluck('permission')->all(),
        )));
    }

    private function holds(User $supervisor, Permission $permission): bool
    {
        try {
            $this->delegation->assertCanGrant($supervisor, [new Grant($permission, Scope::Assigned)]);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private static function membership(User $rep): Membership
    {
        return Membership::query()->where('user_id', $rep->id)->firstOrFail();
    }
}
