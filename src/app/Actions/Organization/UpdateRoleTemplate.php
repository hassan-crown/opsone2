<?php

namespace App\Actions\Organization;

use App\Access\Authorizer;
use App\Access\Enums\Audience;
use App\Access\Enums\Scope;
use App\Access\Permission;
use App\Access\SessionRevoker;
use App\Models\Membership;
use App\Models\RoleTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-04: replace the permissions (each with its default scope) of a role template. A template is shared by every
 * member holding it, so editing needs facility-wide P-04, and the editor may only add a permission they hold
 * facility-wide themselves. The owner template is locked. Every holder's sessions are revoked in the same
 * transaction so the change applies at once (matrix: «تغيير الصلاحيات يطبق على الجلسات الحالية»).
 */
final class UpdateRoleTemplate
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly SessionRevoker $sessions,
    ) {}

    /**
     * @param  list<array{permission: string, scope: string}>  $grants
     */
    public function handle(User $actor, RoleTemplate $template, array $grants): RoleTemplate
    {
        $this->access->authorize($actor, Permission::P04);
        $this->assertFacilityWide($actor, Permission::P04);

        if ($template->is_owner) {
            throw ValidationException::withMessages(['role' => __('org.roles.owner_locked')]);
        }

        $next = $this->normalize($grants);

        return DB::transaction(function () use ($actor, $template, $next) {
            $locked = RoleTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $current = $locked->grants()->get()->mapWithKeys(fn ($g) => [$g->permission->value => $g->scope->value])->all();

            foreach ($next as $code => $scope) {
                if (($current[$code] ?? null) !== $scope) {
                    $this->assertFacilityWide($actor, Permission::from($code));
                }
            }

            $locked->grants()->delete();
            foreach ($next as $code => $scope) {
                $locked->grants()->create(['permission' => $code, 'scope' => $scope]);
            }

            Membership::query()
                ->whereHas('roles', fn ($q) => $q->where('role_template_id', $locked->id))
                ->pluck('user_id')
                ->each(fn (int $userId) => $this->sessions->revoke($userId));

            return $locked;
        });
    }

    /**
     * @param  list<array{permission: string, scope: string}>  $grants
     * @return array<string, string>
     */
    private function normalize(array $grants): array
    {
        $result = [];
        foreach ($grants as $grant) {
            $permission = Permission::tryFrom($grant['permission']);
            $scope = Scope::tryFrom($grant['scope']);
            if ($permission === null || $scope === null || $permission === Permission::P07
                || $permission->audience() !== Audience::Staff || ! $permission->allowsScope($scope)) {
                throw ValidationException::withMessages(['grants' => __('org.roles.invalid_grant')]);
            }
            $result[$permission->value] = $scope->value;
        }

        return $result;
    }

    private function assertFacilityWide(User $actor, Permission $permission): void
    {
        foreach ($this->access->grantsFor($actor, $permission) as $grant) {
            if ($grant->scope === Scope::Tenant) {
                return;
            }
        }

        throw new AuthorizationException(__('access.denied.delegation', ['permission' => $permission->value]));
    }
}
