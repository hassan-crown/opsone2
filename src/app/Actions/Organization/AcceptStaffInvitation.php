<?php

namespace App\Actions\Organization;

use App\Access\Exceptions\InvalidTransition;
use App\Access\OwnershipProof;
use App\Actions\StateMachines\M01Invitation\T01AcceptInvitation;
use App\Models\Invitation;
use App\Models\Membership;
use Illuminate\Validation\ValidationException;

/**
 * Staff invitation acceptance with the ownership-proof code (Q-17) in front of M-01-T01.
 */
final class AcceptStaffInvitation
{
    public function __construct(
        private readonly OwnershipProof $proof,
        private readonly T01AcceptInvitation $accept,
    ) {}

    public function handle(string $token, string $code, string $name, string $password): Membership
    {
        $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->first();

        $state = OrgInvitationState::of($invitation);
        if ($state !== OrgInvitationState::Valid || $invitation === null) {
            throw ValidationException::withMessages(['token' => __('org.accept.state_'.$state->value)]);
        }

        $this->proof->verify($invitation, $code);

        try {
            return $this->accept->handle($token, $name, $password);
        } catch (InvalidTransition $e) {
            throw ValidationException::withMessages(['token' => __('org.accept.condition_'.$e->condition)]);
        }
    }
}
