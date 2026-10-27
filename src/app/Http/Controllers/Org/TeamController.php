<?php

namespace App\Http\Controllers\Org;

use App\Access\Exceptions\InvalidTransition;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Actions\Organization\SendInvitationLink;
use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Actions\StateMachines\M01Invitation\T03CancelInvitation;
use App\Actions\StateMachines\M01Invitation\T04RenewInvitation;
use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationUnitStatus;
use App\Http\Requests\Org\InviteStaffRequest;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Organization\OrgPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team and roles (ORG-03/04/09): the members list, the invitations list and the invite form (P-04/P-05/P-06).
 */
class TeamController extends OrgController
{
    private const VIEW = [Permission::P04, Permission::P05, Permission::P06];

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->authorizeAny($actor, self::VIEW);

        $status = in_array($request->query('status'), ['active', 'disabled'], true) ? $request->query('status') : null;
        $search = trim((string) $request->query('q', ''));
        $role = $request->integer('role') ?: null;

        $members = Membership::query()
            ->with(['user', 'department', 'branches', 'roles.template.grants'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($role, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('role_template_id', $role)))
            ->orderBy('id')
            ->get()
            ->filter(fn (Membership $m) => $this->canSee($actor, $m))
            ->values();

        return Inertia::render('org/team/index', [
            'tab' => 'members',
            'filters' => ['status' => $status, 'q' => $search, 'role' => $role],
            'members' => $members->map(fn (Membership $m) => $this->memberRow($actor, $m))->all(),
            'roles' => OrgPresenter::assignableRoles(),
            'counts' => $this->counts($actor),
            'seats' => $this->seats(),
            'canInvite' => $this->access->can($actor, Permission::P05),
            'canRoles' => $this->access->can($actor, Permission::P04),
        ]);
    }

    public function invitations(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->authorizeAny($actor, self::VIEW);

        $names = RoleTemplate::query()->get()->mapWithKeys(fn (RoleTemplate $t) => [$t->id => $t->name()]);
        $rows = Invitation::query()->with('invitedBy.user')->orderByDesc('id')->get()
            ->filter(fn (Invitation $i) => $this->access->can($actor, Permission::P05, $i))
            ->map(function (Invitation $i) use ($names, $actor) {
                $pending = $i->status === InvitationStatus::Pending;
                $expired = $i->status === InvitationStatus::Expired || ($pending && $i->isPastExpiry());

                return [
                    'id' => $i->id,
                    'name' => $i->name,
                    'email' => $i->email,
                    'status' => $expired ? 'expired' : $i->status->value,
                    'expires_at' => $i->expires_at->toIso8601String(),
                    'roles' => array_map(fn (array $r) => $names[$r['role_template_id']] ?? '—', $i->roles),
                    'branches' => Branch::query()->whereIn('id', $i->accessBranchIds())->get()->map(fn ($b) => OrgPresenter::name($b))->all(),
                    'invited_by' => $i->invitedBy?->user->name,
                    'can' => [
                        'renew' => ($pending || $expired) && $this->access->can($actor, Permission::P05, $i),
                        'cancel' => $pending && ! $expired,
                    ],
                ];
            })->values()->all();

        return Inertia::render('org/team/index', [
            'tab' => 'invitations',
            'filters' => ['status' => null, 'q' => '', 'role' => null],
            'invitations' => $rows,
            'roles' => OrgPresenter::assignableRoles(),
            'counts' => $this->counts($actor),
            'seats' => $this->seats(),
            'canInvite' => $this->access->can($actor, Permission::P05),
            'canRoles' => $this->access->can($actor, Permission::P04),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P05);

        return Inertia::render('org/team/invite', [
            'roles' => OrgPresenter::assignableRoles(),
            'branches' => $this->invitableBranches($actor),
            'departments' => Department::query()->where('status', OrganizationUnitStatus::Active->value)->orderBy('id')->get()
                ->map(fn ($d) => ['id' => $d->id, 'name' => OrgPresenter::name($d)])->all(),
            'scopes' => $this->scopeOptions($actor),
            'seats' => $this->seats(),
        ]);
    }

    public function store(
        InviteStaffRequest $request,
        T00CreateInvitation $create,
        SendInvitationLink $send,
    ): RedirectResponse {
        $actor = $this->actor($request);

        try {
            [$invitation, $token] = $create->handle($actor, $request->invitation());
        } catch (AuthorizationException $e) {
            return back()->withErrors(['roles' => $e->getMessage()])->withInput();
        } catch (InvalidTransition $e) {
            return back()->withErrors(['email' => __('org.invite.error_'.$e->condition)])->withInput();
        }

        $send->handle($invitation, $token);
        $this->toast(__('org.invite.sent', ['email' => $invitation->email]));

        return $request->validated('from') === 'wizard' ? back() : to_route('org.team.invitations');
    }

    public function renew(Request $request, Invitation $invitation, T04RenewInvitation $renew, SendInvitationLink $send): RedirectResponse
    {
        [$new, $token] = $renew->handle($this->actor($request), $invitation);
        $send->handle($new, $token);
        $this->toast(__('org.invite.renewed', ['email' => $new->email]));

        return to_route('org.team.invitations');
    }

    public function cancel(Request $request, Invitation $invitation, T03CancelInvitation $cancel): RedirectResponse
    {
        $cancel->handle($this->actor($request), $invitation);
        $this->toast(__('org.invite.cancelled', ['email' => $invitation->email]));

        return to_route('org.team.invitations');
    }

    private function canSee(User $actor, Membership $member): bool
    {
        foreach (self::VIEW as $permission) {
            if ($this->access->can($actor, $permission, $member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(User $actor, Membership $m): array
    {
        $owner = $m->isOwner();
        $active = $m->status === MembershipStatus::Active;

        return [
            'id' => $m->id,
            'name' => $m->user->name,
            'email' => $m->user->email,
            'job_title' => $m->job_title,
            'status' => $m->status->value,
            'is_owner' => $owner,
            'is_self' => $m->user_id === $actor->id,
            'department' => $m->department === null ? null : OrgPresenter::name($m->department),
            'branches' => $m->branches->map(fn ($b) => OrgPresenter::name($b))->values()->all(),
            'roles' => $owner
                ? [['name' => __('org.team.owner_role'), 'scope' => 'tenant']]
                : $m->roles->map(fn ($r) => ['name' => $r->template->name(), 'scope' => OrgPresenter::roleScope($r)])->values()->all(),
            'can' => [
                'access' => ! $owner && $active && $this->access->can($actor, Permission::P04, $m),
                'disable' => ! $owner && $active && $m->user_id !== $actor->id && $this->access->can($actor, Permission::P06, $m),
                'reactivate' => ! $active && $this->access->can($actor, Permission::P06, $m),
            ],
        ];
    }

    /**
     * @return array{members: int, invitations: int, roles: int}
     */
    private function counts(User $actor): array
    {
        return [
            'members' => Membership::query()->get()->filter(fn (Membership $m) => $this->canSee($actor, $m))->count(),
            'invitations' => Invitation::query()->where('status', InvitationStatus::Pending->value)->where('expires_at', '>', now())->get()
                ->filter(fn (Invitation $i) => $this->access->can($actor, Permission::P05, $i))->count(),
            'roles' => RoleTemplate::query()->count(),
        ];
    }

    /**
     * @return array{used: int, limit: int|null}
     */
    private function seats(): array
    {
        return $this->usage('seats', app(LicenseLimits::class)->usedSeats());
    }

    /**
     * Branches the actor may place an invitee in (P-05 over the placement).
     *
     * @return list<array{id: int, name: string}>
     */
    private function invitableBranches(User $actor): array
    {
        return array_values(Branch::query()->where('status', OrganizationUnitStatus::Active->value)->orderBy('id')->get()
            ->filter(fn (Branch $b) => $this->access->can($actor, Permission::P05, new Invitation(['branch_ids' => [$b->id]])))
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all());
    }

    /**
     * Scope breadths the actor may offer; facility-wide only when they hold P-05 facility-wide.
     *
     * @return list<string>
     */
    private function scopeOptions(User $actor): array
    {
        $options = ['default', 'branches', 'department'];

        return $this->hasFacilityWide($actor, Permission::P05) ? ['default', 'tenant', 'region', 'branches', 'department'] : $options;
    }
}
