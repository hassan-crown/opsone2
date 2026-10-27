<?php

namespace App\Http\Controllers\Org;

use App\Access\OwnershipProof;
use App\Actions\Organization\AcceptStaffInvitation;
use App\Actions\Organization\OrgInvitationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\AcceptInvitationRequest;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\RoleTemplate;
use App\Organization\OrgPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff invitation page (InviteAccept): shows what the invitation grants, asks for the emailed code and a
 * password, then signs the new member in.
 */
class InviteAcceptController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->find($token);
        $state = OrgInvitationState::of($invitation);
        $facility = Facility::current();

        $details = null;
        if ($state === OrgInvitationState::Valid && $invitation !== null) {
            $invitation->load('invitedBy.user');
            $names = RoleTemplate::query()->whereIn('id', array_column($invitation->roles, 'role_template_id'))->get()->map(fn ($t) => $t->name())->all();

            $details = [
                'name' => $invitation->name,
                'email' => $invitation->email,
                'masked_email' => self::mask($invitation->email),
                'code_sent' => $invitation->code_sent_at !== null && $invitation->code_expires_at?->isFuture() === true,
                'inviter' => $invitation->invitedBy?->user->name,
                'roles' => $names,
                'department' => $invitation->department_id === null ? null : OrgPresenter::name(Department::query()->findOrFail($invitation->department_id)),
                'branches' => Branch::query()->whereIn('id', $invitation->accessBranchIds())->get()->map(fn ($b) => OrgPresenter::name($b))->all(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ];
        }

        return Inertia::render('auth/invite-accept', [
            'state' => $state->value,
            'token' => $token,
            'invitation' => $details,
            'facility' => $facility === null ? null : OrgPresenter::name($facility),
            'resendSeconds' => OwnershipProof::RESEND_SECONDS,
        ]);
    }

    public function sendCode(string $token, OwnershipProof $proof): RedirectResponse
    {
        $invitation = $this->find($token);
        abort_unless(OrgInvitationState::of($invitation) === OrgInvitationState::Valid && $invitation !== null, 404);

        $proof->send($invitation);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('org.accept.code_sent')]);

        return back();
    }

    public function accept(AcceptInvitationRequest $request, string $token, AcceptStaffInvitation $accept): RedirectResponse
    {
        $membership = $accept->handle($token, $request->validated('code'), $request->validated('name'), $request->validated('password'));

        Auth::login($membership->user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }

    private function find(string $token): ?Invitation
    {
        return Invitation::query()->where('token_hash', Invitation::hashToken($token))->first();
    }

    private static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }
}
