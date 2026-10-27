<?php

namespace App\Actions\StateMachines\M01Invitation;

use App\Access\DelegationGuard;
use App\Access\Exceptions\InvalidTransition;
use App\Access\GrantResolver;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M22Membership\T00CreateMembership;
use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\StateMachine;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * M-01-T01: the invitee accepts a valid, single-use link and sets a password; the account and an active
 * membership are created. Possessing the emailed link is the proof of email ownership (Q-17 default: email).
 * Roles and scope are re-validated at acceptance against the inviter's current access.
 */
final class T01AcceptInvitation
{
    public function __construct(
        private readonly GrantResolver $resolver,
        private readonly DelegationGuard $delegation,
        private readonly TransitionRecorder $transitions,
        private readonly T00CreateMembership $createMembership,
    ) {}

    public function handle(string $token, string $name, string $password): Membership
    {
        return DB::transaction(function () use ($token, $name, $password) {
            $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->lockForUpdate()->first();

            if ($invitation === null) {
                throw new InvalidTransition(StateMachine::M01, 'T01', 'invalid_token');
            }
            if ($invitation->status !== InvitationStatus::Pending) {
                throw new InvalidTransition(StateMachine::M01, 'T01', 'not_pending');
            }
            if ($invitation->isPastExpiry()) {
                throw new InvalidTransition(StateMachine::M01, 'T01', 'expired');
            }
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw new InvalidTransition(StateMachine::M01, 'T01', 'account_exists');
            }

            $inviter = $invitation->invitedBy;
            if ($inviter === null || $inviter->status !== MembershipStatus::Active) {
                throw new InvalidTransition(StateMachine::M01, 'T01', 'inviter_inactive');
            }
            try {
                $grants = $this->resolver->resolvePayload($invitation->department_id, $invitation->accessBranchIds(), $invitation->roles);
                $this->delegation->assertCanGrant($inviter->user, $grants);
            } catch (InvalidArgumentException|AuthorizationException) {
                throw new InvalidTransition(StateMachine::M01, 'T01', 'grant_no_longer_valid');
            }

            $user = new User(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $membership = $this->createMembership->handle(
                $user,
                $invitation->department_id,
                $invitation->accessBranchIds(),
                $invitation->roles,
                $invitation->job_title,
                $user,
                'invitation',
            );

            $invitation->forceFill([
                'status' => InvitationStatus::Accepted,
                'accepted_at' => now(),
                'membership_id' => $membership->id,
            ])->save();

            $this->transitions->record(
                StateMachine::M01, 'T01', $invitation, InvitationStatus::Pending, InvitationStatus::Accepted, $user, TransitionRecorder::SOURCE_WEB,
            );

            return $membership;
        });
    }
}
