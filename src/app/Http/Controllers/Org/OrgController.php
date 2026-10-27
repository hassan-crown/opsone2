<?php

namespace App\Http\Controllers\Org;

use App\Access\Authorizer;
use App\Access\Enums\Scope;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Licensing\CurrentLicense;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

abstract class OrgController extends Controller
{
    public function __construct(protected readonly Authorizer $access) {}

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * Allows the request when the actor holds at least one of the permissions; otherwise raises the first one's
     * denial (403 with the Gate's reason).
     *
     * @param  list<Permission>  $permissions
     */
    protected function authorizeAny(User $actor, array $permissions, ?object $record = null): void
    {
        foreach ($permissions as $permission) {
            if ($this->access->can($actor, $permission, $record)) {
                return;
            }
        }

        $this->access->authorize($actor, $permissions[0], $record);
    }

    /**
     * Whether the actor holds the permission over the whole facility (not just a branch, region or department).
     */
    protected function hasFacilityWide(User $actor, Permission $permission): bool
    {
        foreach ($this->access->grantsFor($actor, $permission) as $grant) {
            if ($grant->scope === Scope::Tenant) {
                return true;
            }
        }

        return false;
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }

    /**
     * @return array{used: int, limit: int|null}
     */
    protected function usage(string $resource, int $used): array
    {
        return ['used' => $used, 'limit' => app(CurrentLicense::class)->limit($resource)];
    }
}
