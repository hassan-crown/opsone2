<?php

namespace App\Http\Controllers\Org;

use App\Access\OwnershipProof;
use App\Actions\Organization\AcceptOwnerInvitation;
use App\Actions\Organization\OrgInvitationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\AcceptInvitationRequest;
use App\Licensing\CurrentLicense;
use App\Models\Facility;
use App\Models\OwnerInvitation;
use App\Organization\OrgPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first owner's invitation page (OwnerInvite): the emailed code proves mailbox ownership (Q-17), then the
 * owner sets a password and enters the setup wizard.
 */
class OwnerInviteController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->find($token);
        $state = OrgInvitationState::of($invitation);
        $facility = Facility::current();

        return Inertia::render('auth/owner-invite', [
            'state' => $state->value,
            'token' => $token,
            'invitee' => $state === OrgInvitationState::Valid && $invitation !== null ? [
                'name' => $invitation->name,
                'email' => $invitation->email,
                'masked_email' => self::mask($invitation->email),
                'code_sent' => $invitation->code_sent_at !== null && $invitation->code_expires_at?->isFuture() === true,
            ] : null,
            'facility' => $facility === null ? null : OrgPresenter::name($facility),
            'plan' => app(CurrentLicense::class)->trusted()?->plan,
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

    public function accept(AcceptInvitationRequest $request, string $token, AcceptOwnerInvitation $accept): RedirectResponse
    {
        $user = $accept->handle($token, $request->validated('code'), $request->validated('name'), $request->validated('password'));

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('org.setup');
    }

    private function find(string $token): ?OwnerInvitation
    {
        return OwnerInvitation::query()->where('token_hash', OwnerInvitation::hashToken($token))->first();
    }

    private static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }
}
