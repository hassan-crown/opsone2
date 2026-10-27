<?php

namespace App\Http\Controllers\Org;

use App\Access\GrantResolver;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Actions\StateMachines\M22Membership\T01DisableMembership;
use App\Actions\StateMachines\M22Membership\T02ReactivateMembership;
use App\Actions\StateMachines\M22Membership\T03UpdateMemberAccess;
use App\Enums\MembershipStatus;
use App\Http\Requests\Org\DisableMemberRequest;
use App\Http\Requests\Org\UpdateMemberAccessRequest;
use App\Models\MemberGrant;
use App\Models\Membership;
use App\Organization\OrgPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One member: details, effective grants, role assignment (M-22-T03), disable and reactivate (M-22-T01/T02).
 */
class MemberController extends OrgController
{
    private const VIEW = [Permission::P04, Permission::P05, Permission::P06];

    public function show(Request $request, Membership $membership): Response
    {
        $actor = $this->actor($request);
        $this->authorizeAny($actor, self::VIEW, $membership);
        $membership->load(['user', 'department', 'branches', 'roles.template.grants', 'grants']);

        $owner = $membership->isOwner();
        $active = $membership->status === MembershipStatus::Active;
        $grants = app(GrantResolver::class)->forMembership($membership, $owner);

        return Inertia::render('org/team/member', [
            'member' => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'job_title' => $membership->job_title,
                'status' => $membership->status->value,
                'is_owner' => $owner,
                'is_self' => $membership->user_id === $actor->id,
                'department' => $membership->department === null ? null : OrgPresenter::name($membership->department),
                'branches' => $membership->branches->map(fn ($b) => OrgPresenter::name($b))->values()->all(),
                'disabled_at' => $membership->disabled_at?->toIso8601String(),
                'disabled_reason' => $membership->disabled_reason,
                'roles' => $owner ? [] : $membership->roles->map(fn ($r) => [
                    'role_template_id' => $r->role_template_id,
                    'scope' => $r->scope->value ?? '',
                    'effective_scope' => OrgPresenter::roleScope($r),
                ])->values()->all(),
            ],
            'grantGroups' => OrgPresenter::groupGrants($grants),
            'overrides' => $membership->grants->map(fn (MemberGrant $g) => [
                'permission' => $g->permission->label(),
                'effect' => $g->effect->value,
                'scope' => $g->scope?->value,
            ])->values()->all(),
            'roleOptions' => OrgPresenter::assignableRoles(),
            'scopes' => $this->hasFacilityWide($actor, Permission::P04)
                ? ['default', 'tenant', 'region', 'branches', 'department']
                : ['default', 'branches', 'department'],
            'can' => [
                'access' => ! $owner && $active && $this->access->can($actor, Permission::P04, $membership),
                'disable' => ! $owner && $active && $membership->user_id !== $actor->id && $this->access->can($actor, Permission::P06, $membership),
                'reactivate' => ! $active && $this->access->can($actor, Permission::P06, $membership),
            ],
            'seats' => $this->usage('seats', app(LicenseLimits::class)->usedSeats()),
        ]);
    }

    public function updateAccess(UpdateMemberAccessRequest $request, Membership $membership, T03UpdateMemberAccess $update): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P04, $membership);

        /** @var list<array{permission: string, effect: string, scope?: string|null, targets?: list<int>|null}> $overrides */
        $overrides = $membership->grants()->get()->map(fn (MemberGrant $g) => [
            'permission' => $g->permission->value,
            'effect' => $g->effect->value,
            'scope' => $g->scope?->value,
            'targets' => $g->targets,
        ])->values()->all();

        try {
            $update->handle($actor, $membership, $request->roles(), $overrides);
        } catch (AuthorizationException $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        }

        $this->toast(__('org.member.access_saved'));

        return to_route('org.team.member', $membership);
    }

    public function confirmDisable(Request $request, Membership $membership): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P06, $membership);
        $membership->load(['user', 'roles.template.grants', 'branches']);

        return Inertia::render('org/team/disable', [
            'member' => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'roles' => $membership->roles->map(fn ($r) => $r->template->name())->values()->all(),
                'branches' => $membership->branches->map(fn ($b) => OrgPresenter::name($b))->values()->all(),
                'is_owner' => $membership->isOwner(),
                'status' => $membership->status->value,
            ],
        ]);
    }

    public function disable(DisableMemberRequest $request, Membership $membership, T01DisableMembership $disable): RedirectResponse
    {
        $disable->handle($this->actor($request), $membership, (string) $request->validated('reason'));
        $this->toast(__('org.member.disabled', ['name' => $membership->user->name]));

        return to_route('org.team.index');
    }

    public function reactivate(Request $request, Membership $membership, T02ReactivateMembership $reactivate): RedirectResponse
    {
        $reactivate->handle($this->actor($request), $membership);
        $this->toast(__('org.member.reactivated', ['name' => $membership->user->name]));

        return back();
    }
}
