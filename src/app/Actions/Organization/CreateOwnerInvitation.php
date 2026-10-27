<?php

namespace App\Actions\Organization;

use App\Enums\InvitationStatus;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\OwnerInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisioning step: invite the facility's first owner (valid seven days). Earlier pending owner invitations
 * are cancelled so only the newest link works. Refuses once the facility has an owner.
 */
final class CreateOwnerInvitation
{
    /**
     * @return array{0: OwnerInvitation, 1: string}
     */
    public function handle(string $email, ?string $name = null): array
    {
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($email, $name) {
            $facility = Facility::query()->lockForUpdate()->first()
                ?? throw new RuntimeException('Seed the facility profile before inviting its owner.');

            if ($facility->owner_membership_id !== null) {
                throw new RuntimeException('The facility already has an owner.');
            }
            if (User::query()->where('email', $email)->exists()) {
                throw new RuntimeException('An account with this email already exists.');
            }

            OwnerInvitation::query()->where('status', InvitationStatus::Pending->value)
                ->update(['status' => InvitationStatus::Cancelled->value]);

            $token = Str::random(64);
            $invitation = new OwnerInvitation(['email' => $email, 'name' => $name]);
            $invitation->fill([
                'token_hash' => OwnerInvitation::hashToken($token),
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
            ])->save();

            return [$invitation, $token];
        });
    }
}
