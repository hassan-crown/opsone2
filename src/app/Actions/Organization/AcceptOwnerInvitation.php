<?php

namespace App\Actions\Organization;

use App\Access\OwnershipProof;
use App\Access\TransitionRecorder;
use App\Enums\InvitationStatus;
use App\Models\OwnerInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The first owner accepts their invitation: the emailed code proves mailbox ownership (Q-17), a password is set,
 * and the account becomes the facility's owner. The link is single use.
 */
final class AcceptOwnerInvitation
{
    public function __construct(
        private readonly OwnershipProof $proof,
        private readonly EstablishOwner $establishOwner,
    ) {}

    public function handle(string $token, string $code, string $name, string $password): User
    {
        $invitation = OwnerInvitation::query()->where('token_hash', OwnerInvitation::hashToken($token))->first();
        $this->assertUsable($invitation);

        $this->proof->verify($invitation, $code);

        return DB::transaction(function () use ($invitation, $name, $password) {
            $locked = OwnerInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $this->assertUsable($locked);

            if (User::query()->where('email', $locked->email)->exists()) {
                throw ValidationException::withMessages(['email' => __('org.accept.account_exists')]);
            }

            $user = new User(['name' => $name, 'email' => $locked->email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->establishOwner->handle($user, TransitionRecorder::SOURCE_WEB);

            $locked->forceFill(['status' => InvitationStatus::Accepted, 'accepted_at' => now()])->save();

            return $user;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertUsable(?OwnerInvitation $invitation): void
    {
        $state = OrgInvitationState::of($invitation);
        if ($state !== OrgInvitationState::Valid) {
            throw ValidationException::withMessages(['token' => __('org.accept.state_'.$state->value)]);
        }
    }
}
