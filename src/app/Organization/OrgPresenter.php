<?php

namespace App\Organization;

use App\Access\Enums\Audience;
use App\Access\Enums\Scope;
use App\Access\Grant;
use App\Access\Permission;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Facility;
use App\Models\MembershipRole;
use App\Models\Region;
use App\Models\RoleTemplate;

/**
 * Shapes organization records for the Inertia pages: locale-aware names, scope summaries, grouped permissions.
 */
final class OrgPresenter
{
    /** Display order of permission groups in the role editor. */
    public const GROUP_ORDER = [
        'organization', 'customers_catalog', 'orders', 'sales', 'agreement', 'operations', 'appointments', 'delivery',
        'inventory', 'finance', 'reports', 'audit', 'site', 'theme', 'support', 'authorized_support', 'subscription',
    ];

    /** Organizational scopes in descending breadth. */
    private const BREADTH = ['tenant', 'region', 'branches', 'department'];

    public static function name(Branch|Region|Department|Facility|RoleTemplate $model): string
    {
        return app()->getLocale() === 'en' && $model->name_en ? $model->name_en : $model->name_ar;
    }

    /**
     * Scope a role assignment effectively carries: its own breadth, else the widest organizational scope the
     * template's grants use, else an intrinsic one (own/assigned).
     */
    public static function roleScope(MembershipRole $role): string
    {
        if ($role->scope !== null) {
            return $role->scope->value;
        }

        $scopes = $role->template->grants->map(fn ($g) => $g->scope->value)->unique()->all();
        foreach (self::BREADTH as $scope) {
            if (in_array($scope, $scopes, true)) {
                return $scope;
            }
        }

        return $scopes[0] ?? 'own';
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public static function targetNames(Scope $scope, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $models = match ($scope) {
            Scope::Branches => Branch::query()->whereIn('id', $ids)->get(),
            Scope::Region => Region::query()->whereIn('id', $ids)->get(),
            Scope::Department => Department::query()->whereIn('id', $ids)->get(),
            default => collect(),
        };

        return array_values($models->map(fn ($m) => self::name($m))->all());
    }

    /**
     * @param  list<Grant>  $grants
     * @return list<array{group: string, items: list<array{code: string, label: string, scope: string, targets: list<string>}>}>
     */
    public static function groupGrants(array $grants): array
    {
        $groups = [];
        foreach ($grants as $grant) {
            $groups[$grant->permission->group()][] = [
                'code' => $grant->permission->value,
                'label' => $grant->permission->label(),
                'scope' => $grant->scope->value,
                'targets' => self::targetNames($grant->scope, $grant->targets),
            ];
        }

        $ordered = [];
        foreach (self::GROUP_ORDER as $group) {
            if (isset($groups[$group])) {
                $ordered[] = ['group' => $group, 'items' => $groups[$group]];
            }
        }

        return $ordered;
    }

    /**
     * Staff permissions of the catalogue available under the license, grouped for the role editor.
     *
     * @param  callable(string): bool  $capabilityAllowed
     * @return list<array{group: string, items: list<array{code: string, label: string, scopes: list<string>}>}>
     */
    public static function catalogue(callable $capabilityAllowed): array
    {
        $groups = [];
        foreach (Permission::cases() as $permission) {
            if ($permission->audience() !== Audience::Staff || $permission === Permission::P07) {
                continue;
            }
            if ($permission->capability() !== null && ! $capabilityAllowed($permission->capability())) {
                continue;
            }
            $groups[$permission->group()][] = [
                'code' => $permission->value,
                'label' => $permission->label(),
                'scopes' => array_map(fn (Scope $s) => $s->value, $permission->allowedScopes()),
            ];
        }

        $ordered = [];
        foreach (self::GROUP_ORDER as $group) {
            if (isset($groups[$group])) {
                $ordered[] = ['group' => $group, 'items' => $groups[$group]];
            }
        }

        return $ordered;
    }

    /**
     * @return list<array{id: int, name: string, is_owner: bool}>
     */
    public static function assignableRoles(): array
    {
        return array_values(RoleTemplate::query()->where('is_owner', false)->orderBy('id')->get()
            ->map(fn (RoleTemplate $t) => ['id' => $t->id, 'name' => $t->name(), 'is_owner' => false])->all());
    }
}
