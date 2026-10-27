<?php

namespace App\Http\Controllers\Org;

use App\Access\Contracts\LicenseCapabilities;
use App\Access\Permission;
use App\Actions\Organization\UpdateRoleTemplate;
use App\Enums\MembershipStatus;
use App\Http\Requests\Org\UpdateRoleRequest;
use App\Models\Membership;
use App\Models\RoleTemplate;
use App\Organization\OrgPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role template editor (ORG-03, P-04): permissions grouped as in the matrix, each with its default scope.
 */
class RoleController extends OrgController
{
    public function index(Request $request): RedirectResponse
    {
        $this->access->authorize($this->actor($request), Permission::P04);

        $first = RoleTemplate::query()->where('is_owner', false)->orderBy('id')->value('id')
            ?? RoleTemplate::query()->orderBy('id')->value('id');

        return to_route('org.roles.edit', ['template' => $first]);
    }

    public function edit(Request $request, RoleTemplate $template): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P04);

        $template->load('grants');
        $licence = app(LicenseCapabilities::class);

        $holders = Membership::query()->with('user')->where('status', MembershipStatus::Active->value)
            ->whereHas('roles', fn ($q) => $q->where('role_template_id', $template->id))->get();

        $members = Membership::query()->where('status', MembershipStatus::Active->value)->with('roles')->get();

        return Inertia::render('org/roles', [
            'roles' => RoleTemplate::query()->orderBy('id')->get()->map(fn (RoleTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name(),
                'is_owner' => $t->is_owner,
                'members' => $t->is_owner ? 1 : $members->filter(fn ($m) => $m->roles->contains('role_template_id', $t->id))->count(),
            ])->all(),
            'role' => [
                'id' => $template->id,
                'name' => $template->name(),
                'is_owner' => $template->is_owner,
                'starter' => $template->key !== null,
                'holders' => $holders->map(fn ($m) => $m->user->name)->values()->all(),
                'grants' => $template->is_owner ? [] : $template->grants->map(fn ($g) => ['permission' => $g->permission->value, 'scope' => $g->scope->value])->values()->all(),
            ],
            'catalogue' => OrgPresenter::catalogue($licence->allows(...)),
            'canEdit' => ! $template->is_owner && $this->hasFacilityWide($actor, Permission::P04),
        ]);
    }

    public function update(UpdateRoleRequest $request, RoleTemplate $template, UpdateRoleTemplate $update): RedirectResponse
    {
        abort_unless($this->hasFacilityWide($this->actor($request), Permission::P04), 403);

        try {
            $update->handle($this->actor($request), $template, $request->grants());
        } catch (AuthorizationException $e) {
            return back()->withErrors(['grants' => $e->getMessage()]);
        }

        $this->toast(__('org.roles.saved'));

        return back();
    }
}
